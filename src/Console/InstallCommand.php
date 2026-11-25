<?php

namespace BarisDev\MediaLibrary\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use BarisDev\MediaLibrary\MediaLibraryServiceProvider;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

class InstallCommand extends Command
{
    protected $signature = 'media-library:install
        {--no-scan : Medya kolonlarını otomatik arama}
        {--force : Mevcut config dosyasının üzerine yaz}';

    protected $description = 'Medya kütüphanesini kurar: config, migration, asset ve kullanım haritası önerileri';

    private const MARKER = '// <media-library:usage>';

    private const NAME_HINT = '/(image|img|photo|picture|logo|icon|cover|thumb|avatar|banner|gallery|media|video|favicon|attachment|document|file)/i';

    private const SKIP_TABLES = ['migrations', 'curator', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'personal_access_tokens', 'notifications', 'settings', 'telescope_entries'];

    public function handle(): int
    {
        $this->components->info('Medya kütüphanesi kuruluyor...');

        $this->callSilently('vendor:publish', [
            '--provider' => MediaLibraryServiceProvider::class,
            '--tag' => 'media-library-config',
            '--force' => (bool) $this->option('force'),
        ]);
        $this->components->task('config/media_library.php yayınlandı');

        $this->ensureMigrations();

        $this->callSilently('filament:assets');
        $this->components->task('Filament asset\'leri güncellendi');

        if (! $this->option('no-scan')) {
            $this->suggestSources();
        }

        $this->callSilently('optimize:clear');

        $this->newLine();
        $this->components->info('Son adım: panel sağlayıcınıza (ör. AdminPanelProvider) ekleyin:');
        $this->line('    ->plugin(\\BarisDev\\MediaLibrary\\MediaLibraryPlugin::make())');
        $this->newLine();
        $this->line('Yeni medya alanı eklemek için config/media_library.php → usage veya:');
        $this->line("    MediaLibrary::track(Post::class, 'cover_image')->editedBy(PostResource::class, 'slug');");

        return self::SUCCESS;
    }

    /**
     * Curator tablosunu (yoksa) kurar, ardından is_active migration'ını Curator'ınkinden
     * SONRA çalışacak bir tarihle yayınlar ve migrate'i çalıştırır.
     */
    protected function ensureMigrations(): void
    {
        $dir = database_path('migrations');
        $files = glob($dir.'/*.php') ?: [];
        $has = function (string $needle) use (&$files): bool {
            return collect($files)->contains(fn ($f) => str_contains(basename($f), $needle));
        };

        if (! Schema::hasTable('curator') && ! $has('_create_curator_table')) {
            $this->components->info('Curator migration\'ı bulunamadı; curator:install çalıştırılıyor...');
            $this->call('curator:install');
            $files = glob($dir.'/*.php') ?: [];
        }

        if (! $has('_add_is_active_to_curator_table') && ! (Schema::hasTable('curator') && Schema::hasColumn('curator', 'is_active'))) {
            $latest = collect($files)
                ->map(fn ($f) => substr(basename($f), 0, 17))
                ->filter(fn ($p) => preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}$/', $p))
                ->max();

            $timestamp = now();
            if ($latest) {
                $last = \Carbon\Carbon::createFromFormat('Y_m_d_His', $latest);
                if ($last && $last->greaterThanOrEqualTo($timestamp)) {
                    $timestamp = $last->addSecond();
                }
            }

            $target = $dir.'/'.$timestamp->format('Y_m_d_His').'_add_is_active_to_curator_table.php';
            copy(__DIR__.'/../../database/migrations/add_is_active_to_curator_table.php.stub', $target);
            $this->components->task('is_active migration\'ı yayınlandı');
        }

        if (confirm('Migration çalıştırılsın mı?', true)) {
            $this->call('migrate');
        }
    }

    protected function suggestSources(): void
    {
        $existing = collect((array) config('media_library.usage', []))
            ->map(fn ($s) => ($s['table'] ?? '').'.'.($s['column'] ?? ''))
            ->all();

        $candidates = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (in_array($name, self::SKIP_TABLES, true)) {
                continue;
            }

            foreach (Schema::getColumns($name) as $column) {
                $key = $name.'.'.$column['name'];

                if (in_array($key, $existing, true) || ! preg_match(self::NAME_HINT, $column['name'])) {
                    continue;
                }

                if ($type = $this->guessType($column)) {
                    $candidates[$key] = ['table' => $name, 'column' => $column['name'], 'type' => $type];
                }
            }
        }

        if ($candidates === []) {
            $this->components->warn('Medya kolonu önerisi bulunamadı; config/media_library.php içindeki usage listesini elle doldurun.');

            return;
        }

        $selected = multiselect(
            label: 'Medya tutan kolonlar bulundu. Kullanım takibine eklenecekleri seçin:',
            options: collect($candidates)->map(fn ($c, $k) => "{$k}  [{$c['type']}]")->all(),
            default: array_keys($candidates),
            hint: 'Türü yanlış tahmin edildiyse sonra config\'ten düzeltin (id | ids | path | json).',
        );

        if ($selected === []) {
            return;
        }

        $lines = collect($selected)->map(fn ($key) => $this->exportLine($candidates[$key]))->implode("\n");

        $path = config_path('media_library.php');
        $contents = is_file($path) ? file_get_contents($path) : '';

        if (! str_contains($contents, self::MARKER)) {
            $this->components->warn('Config içinde işaret satırı bulunamadı; şu satırları usage dizisine elle ekleyin:');
            $this->line($lines);

            return;
        }

        $pos = strpos($contents, self::MARKER);
        $eol = strpos($contents, "\n", $pos);
        file_put_contents($path, substr($contents, 0, $eol + 1).$lines."\n".substr($contents, $eol + 1));

        $this->components->task(count($selected).' kaynak config/media_library.php dosyasına eklendi');
    }

    /**
     * @param  array<string, mixed>  $column
     */
    protected function guessType(array $column): ?string
    {
        $name = Str::lower($column['name']);
        $type = Str::lower((string) ($column['type_name'] ?? ''));

        $isList = (bool) preg_match('/(gallery|images|photos|pictures|files|attachments|_ids)$/', $name);

        if (str_contains($type, 'json') || $type === 'text') {
            return $isList ? 'ids' : null; // iç içe JSON'un anahtarları tahmin edilemez
        }

        if (str_contains($type, 'int')) {
            return 'id';
        }

        if (str_contains($type, 'char') || str_contains($type, 'string')) {
            return 'path';
        }

        return null;
    }

    /**
     * @param  array{table: string, column: string, type: string}  $c
     */
    protected function exportLine(array $c): string
    {
        $titleColumn = collect(['title', 'name'])->first(fn ($col) => Schema::hasColumn($c['table'], $col));
        $resource = 'App\\Filament\\Resources\\'.Str::studly(Str::singular($c['table'])).'Resource';

        $parts = [
            "'table' => '{$c['table']}'",
            "'column' => '{$c['column']}'",
            "'type' => '{$c['type']}'",
            "'label' => '".addslashes(Str::headline($c['table'].' '.$c['column']))."'",
        ];

        if ($titleColumn) {
            $parts[] = "'title' => '{$titleColumn}'";
        }

        if (class_exists($resource)) {
            $parts[] = "'edit' => \\{$resource}::class";

            if (Schema::hasColumn($c['table'], 'slug')) {
                $parts[] = "'route_key' => 'slug'";
            }
        }

        return '        ['.implode(', ', $parts).'],';
    }
}
