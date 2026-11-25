<?php

namespace Tachyon\MediaLibrary\Filament\Pages;

use Tachyon\MediaLibrary\Filament\Actions\MultiUploadAction;
use Tachyon\MediaLibrary\Filament\CuratorMediaResource;
use Tachyon\MediaLibrary\Filament\MediaDirectoryManager;
use Tachyon\MediaLibrary\Models\Media;
use Tachyon\MediaLibrary\Support\LibraryVisibility;
use Tachyon\MediaLibrary\Support\MediaDeleter;
use Tachyon\MediaLibrary\Support\MediaUsageScanner;
use Tachyon\MediaLibrary\Support\UntrackedMediaImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Medya Kütüphanesi (media-library paketinin arayüzü, Curator verisiyle).
 *
 * Kartlar Curator kayıtlarından gelir; id / yol / klasör değerlerine dokunulmaz.
 * Kullanım bilgisi MediaUsageScanner ile canlı hesaplanır.
 */
class ListMedia extends Page
{
    use WithPagination;

    protected static string $resource = CuratorMediaResource::class;

    protected string $view = 'media-library::media-library';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Gezgin gibi açık olan klasör ('' = en üst seviye) */
    #[Url(as: 'klasor', except: '')]
    public string $path = '';

    #[Url(as: 'kullanim', except: 'all')]
    public string $usage = 'all';

    #[Url(as: 'tur', except: 'all')]
    public string $kind = 'all';

    /** @var list<int> */
    public array $selected = [];

    /** Silme penceresinde gösterilen özet */
    public array $deletePlan = [];

    private ?MediaUsageScanner $scanner = null;

    private ?array $missingIds = null;

    /** @var list<string>|null */
    private ?array $directories = null;

    /** @var array<string, int>|null klasör => görünür dosya sayısı (yalnız o klasördeki) */
    private ?array $directoryCounts = null;

    public function mount(): void
    {
        MediaDirectoryManager::ensureDefaultDirectory();
    }

    public function getTitle(): string
    {
        return 'Medya Kütüphanesi';
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'path', 'usage', 'kind'], true)) {
            $this->resetPage();
        }
    }

    /** Klasöre gir (gezgindeki çift tık yerine tek tık). */
    public function openFolder(string $path): void
    {
        // Yol olduğu gibi kullanılır (slug'a çevrilmez: özel_klasör vb. bozulmasın).
        $this->path = collect(explode('/', str_replace('\\', '/', $path)))
            ->filter(fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..')
            ->implode('/');
        $this->search = '';
        $this->resetPage();
    }

    public function goUp(): void
    {
        $this->openFolder(str_contains($this->path, '/') ? substr($this->path, 0, strrpos($this->path, '/')) : '');
    }

    /** Arama veya filtre varken açık klasörün altındaki her şey taranır. */
    public function isFiltering(): bool
    {
        return trim($this->search) !== '' || $this->usage !== 'all' || $this->kind !== 'all';
    }

    public function setUsage(string $usage): void
    {
        $this->usage = in_array($usage, ['all', 'used', 'unused', 'missing'], true) ? $usage : 'all';
        $this->resetPage();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** Silme penceresini hazırlar; henüz hiçbir şey silinmez. */
    public function prepareDelete(): void
    {
        $ids = $this->selectedIds();

        if ($ids === []) {
            return;
        }

        $scanner = $this->scanner();
        $records = Media::query()->whereIn('id', $ids)->get();

        $blocked = $records
            ->filter(fn (Media $media): bool => $scanner->isUsed($media->id))
            ->map(fn (Media $media): array => [
                'id' => $media->id,
                'name' => $media->getPrettyName(),
                'usages' => $scanner->usagesFor($media->id),
            ])
            ->values()
            ->all();

        $this->deletePlan = [
            'total' => $records->count(),
            'unused' => $records->count() - count($blocked),
            'blocked' => $blocked,
        ];

        $this->dispatch('open-modal', id: 'ml-delete');
    }

    public function confirmDelete(bool $force = false): void
    {
        $result = app(MediaDeleter::class)->delete($this->selectedIds(), $force);

        $this->dispatch('close-modal', id: 'ml-delete');
        $this->deletePlan = [];
        $this->selected = array_values(array_map('intval', array_keys($result['blocked'])));
        $this->scanner = null;

        $deleted = count($result['deleted']);
        $kept = count($result['blocked']);

        Notification::make()
            ->title($deleted.' dosya silindi')
            ->body($kept > 0 ? $kept.' kullanımdaki dosyaya dokunulmadı (seçili kaldı).' : null)
            ->success()
            ->send();

        if ($result['errors'] !== []) {
            Notification::make()
                ->title(count($result['errors']).' dosya silinemedi')
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importUntracked')
                ->label('Kütüphaneyi Tara')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Kayıtsız dosyaları ekle')
                ->modalDescription(function (): string {
                    $scan = app(UntrackedMediaImporter::class)->scan();
                    $count = count($scan['importable']);

                    return $count === 0
                        ? 'Diskteki tüm dosyalar zaten kütüphanede.'
                        : "Diskte olup kütüphanede görünmeyen {$count} dosya kütüphaneye eklenecek. Dosyalar taşınmaz, yeniden adlandırılmaz.";
                })
                ->modalSubmitActionLabel('Ekle')
                ->action(function (UntrackedMediaImporter $importer): void {
                    $result = $importer->import();

                    Notification::make()
                        ->title($result['imported'].' dosya kütüphaneye eklendi')
                        ->body($result['failed'] !== [] ? count($result['failed']).' dosya eklenemedi.' : null)
                        ->success()
                        ->send();
                }),
            Action::make('createFolder')
                ->label('Yeni Klasör')
                ->icon('heroicon-o-folder-plus')
                ->color('gray')
                ->schema([
                    TextInput::make('name')
                        ->label('Klasör adı')
                        ->required()
                        ->maxLength(MediaDirectoryManager::MAX_NAME_LENGTH)
                        ->default(fn (): string => $this->currentDirectory() ? $this->currentDirectory().'/' : '')
                        ->helperText('Örn: ornek veya ornek/{slug}. Alt klasör için / kullanın.'),
                ])
                ->modalHeading('Yeni Klasör Oluştur')
                ->modalSubmitActionLabel('Oluştur')
                ->action(function (array $data): void {
                    $path = MediaDirectoryManager::create($data['name']);

                    Notification::make()->title('Klasör oluşturuldu')->body($path)->success()->send();
                }),
            MultiUploadAction::make()
                ->label('Dosya Yükle')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->fillForm(fn (): array => [
                    'directory' => $this->currentDirectory() ?: MediaDirectoryManager::defaultDirectory(),
                ]),
        ];
    }

    protected function getViewData(): array
    {
        $files = $this->filteredQuery()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) config('media_library.per_page', 48));

        $filtering = $this->isFiltering();

        return [
            'files' => $files,
            'cards' => $this->cards($files),
            'stats' => $this->stats(),
            'filtering' => $filtering,
            // Gezinmede açık klasörün alt klasörleri; aramada adı eşleşen
            // klasörler (gezgindeki gibi). Yalnız ilk sayfada gösterilir.
            'childFolders' => $files->currentPage() !== 1 ? [] : match (true) {
                trim($this->search) !== '' => $this->matchingFolders(trim($this->search)),
                $filtering => [],
                default => $this->childFolders(),
            },
            'breadcrumbs' => $this->breadcrumbs(),
        ];
    }

    private function filteredQuery(): Builder
    {
        $query = LibraryVisibility::apply(Media::query());

        if ($this->isFiltering()) {
            // Arama/filtre: açık klasör ve tüm alt klasörleri
            if ($this->path !== '') {
                $query->where(fn (Builder $q) => $q
                    ->where('directory', $this->path)
                    ->orWhere('directory', 'like', addcslashes($this->path, '%_\\').'/%'));
            }
        } elseif ($this->path === '') {
            // Gezinme: yalnız bu klasörün kendi dosyaları (Windows Gezgini gibi)
            $query->where(fn (Builder $q) => $q->whereNull('directory')->orWhere('directory', ''));
        } else {
            $query->where('directory', $this->path);
        }

        if (($term = trim($this->search)) !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            // Yalnız dosyanın kendi adı/başlığı/alt metni aranır; klasör adı
            // yolda geçtiği için yol aranırsa klasörün tüm içeriği gelirdi.
            // Klasör adı eşleşmeleri matchingFolders() ile kutucuk olarak çıkar.
            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhere('alt', 'like', $like));
        }

        match ($this->kind) {
            'image' => $query->where('type', 'like', 'image/%'),
            'video' => $query->where('type', 'like', 'video/%'),
            'document' => $query->where('type', 'not like', 'image/%')->where('type', 'not like', 'video/%'),
            default => null,
        };

        match ($this->usage) {
            'used' => $query->whereIn('id', $this->scanner()->usedIds() ?: [0]),
            'unused' => $query->whereNotIn('id', $this->scanner()->usedIds() ?: [0]),
            'missing' => $query->whereIn('id', $this->missingIds() ?: [0]),
            default => null,
        };

        return $query;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cards(LengthAwarePaginator $files): array
    {
        $scanner = $this->scanner();

        return collect($files->items())->map(function (Media $media) use ($scanner): array {
            $ext = strtolower((string) $media->ext);
            $type = (string) $media->type;
            $exists = Storage::disk($media->disk)->exists((string) $media->path);
            $usages = $scanner->usagesFor($media->id);

            $thumb = null;
            if ($exists && str_starts_with($type, 'image/')) {
                $thumb = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true)
                    ? $media->thumbnail_url
                    : $media->url;
            }

            return [
                'id' => $media->id,
                'name' => $media->getPrettyName(),
                'path' => $media->path,
                'directory' => $media->directory ?: 'kök',
                'ext' => $ext !== '' ? $ext : '?',
                'kind' => str_starts_with($type, 'image/') ? 'image' : (str_starts_with($type, 'video/') ? 'video' : 'document'),
                'thumb' => $thumb,
                'url' => $exists ? $media->url : null,
                'edit_url' => CuratorMediaResource::getUrl('edit', ['record' => $media->id]),
                'size' => self::formatSize((int) $media->size),
                'dimensions' => $media->width && $media->height ? $media->width.'×'.$media->height : null,
                'usages' => $usages,
                'exists' => $exists,
                'is_active' => (bool) ($media->is_active ?? true),
            ];
        })->all();
    }

    /**
     * @return array{total: int, used: int, unused: int, size: string}
     */
    private function stats(): array
    {
        $total = LibraryVisibility::apply(Media::query())->count();
        $used = LibraryVisibility::apply(Media::query())->whereIn('id', $this->scanner()->usedIds() ?: [0])->count();

        return [
            'total' => $total,
            'used' => $used,
            'unused' => $total - $used,
            'size' => self::formatSize((int) LibraryVisibility::apply(Media::query())->sum('size')),
        ];
    }

    /**
     * Açık klasörün bir alt seviyesindeki klasörler (gezgindeki gibi).
     *
     * @return list<array{path: string, name: string, files: int, folders: int}>
     */
    private function childFolders(): array
    {
        $prefix = $this->path === '' ? '' : $this->path.'/';
        $children = [];

        // Dosya sayıları (alt klasörler dahil, yalnız kütüphanede görünen dosyalar)
        foreach ($this->directoryCounts() as $directory => $count) {
            if ($directory === '' || ($prefix !== '' && ! str_starts_with($directory, $prefix))) {
                continue;
            }

            $rest = substr($directory, strlen($prefix));
            $name = explode('/', $rest)[0];

            if ($name === '') {
                continue;
            }

            $children[$name]['files'] = ($children[$name]['files'] ?? 0) + $count;
        }

        // Diskteki klasörler: boş (yeni oluşturulmuş) klasörler de görünsün,
        // alt klasör sayısı da buradan çıkar.
        $excluded = (array) config('media_library.excluded_directories', []);

        foreach ($this->directories() as $directory) {
            if ($prefix !== '' && ! str_starts_with($directory, $prefix)) {
                continue;
            }

            // Özel ekler gibi kütüphane dışı klasörler gösterilmez.
            if (in_array(explode('/', $directory)[0], $excluded, true)) {
                continue;
            }

            $rest = substr($directory, strlen($prefix));

            if ($rest === '') {
                continue;
            }

            $segments = explode('/', $rest);
            $name = $segments[0];
            $children[$name]['files'] ??= 0;
            $children[$name]['folders'] ??= 0;

            if (count($segments) === 2) {
                $children[$name]['folders']++;
            }
        }

        $disk = Storage::disk((string) config('curator.default_disk', 'public'));

        return collect($children)
            ->map(fn (array $info, string $name): array => [
                'path' => $prefix.$name,
                'name' => $name,
                'files' => $info['files'] ?? 0,
                'folders' => $info['folders'] ?? 0,
            ])
            // İçinde yalnız gizli türler (Excel) olan klasörü gösterme; tamamen boş klasör görünür.
            ->reject(fn (array $folder): bool => $folder['files'] === 0
                && $folder['folders'] === 0
                && $disk->allFiles($folder['path']) !== [])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Arama: açık klasör ve altındaki tüm klasörlerden adı eşleşenler.
     * Tire/alt çizgi ile boşluk aynı sayılır ("taze havali" = "taze-havali").
     *
     * @return list<array{path: string, name: string, files: int, folders: int, parent: string}>
     */
    private function matchingFolders(string $term): array
    {
        $normalize = fn (string $value): string => mb_strtolower(trim(preg_replace('/[\s\-_]+/u', ' ', $value)));
        $needle = $normalize($term);

        if ($needle === '') {
            return [];
        }

        $prefix = $this->path === '' ? '' : $this->path.'/';
        $excluded = (array) config('media_library.excluded_directories', []);
        $all = $this->directories();
        $counts = $this->directoryCounts();

        return collect($all)
            ->filter(fn (string $dir): bool => ($prefix === '' || str_starts_with($dir, $prefix))
                && ! in_array(explode('/', $dir)[0], $excluded, true)
                && str_contains($normalize(basename($dir)), $needle))
            ->map(function (string $dir) use ($all, $counts): array {
                $files = 0;
                foreach ($counts as $d => $c) {
                    if ($d === $dir || str_starts_with($d, $dir.'/')) {
                        $files += $c;
                    }
                }

                $folders = collect($all)->filter(fn (string $d): bool => str_starts_with($d, $dir.'/') && ! str_contains(substr($d, strlen($dir) + 1), '/'))->count();

                return [
                    'path' => $dir,
                    'name' => basename($dir),
                    'files' => $files,
                    'folders' => $folders,
                    'parent' => str_contains($dir, '/') ? dirname($dir) : '',
                ];
            })
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
            ->take(60)
            ->values()
            ->all();
    }

    /**
     * @return list<array{path: string, name: string}>
     */
    private function breadcrumbs(): array
    {
        $crumbs = [];
        $current = '';

        foreach (array_filter(explode('/', $this->path)) as $segment) {
            $current = $current === '' ? $segment : $current.'/'.$segment;
            $crumbs[] = ['path' => $current, 'name' => $segment];
        }

        return $crumbs;
    }

    /**
     * @return array<string, int>
     */
    private function directoryCounts(): array
    {
        if ($this->directoryCounts !== null) {
            return $this->directoryCounts;
        }

        $this->directoryCounts = [];

        $rows = LibraryVisibility::apply(Media::query())
            ->selectRaw('directory, COUNT(*) as c')
            ->groupBy('directory')
            ->pluck('c', 'directory');

        foreach ($rows as $directory => $count) {
            $key = trim(str_replace('\\', '/', (string) $directory), '/');
            $this->directoryCounts[$key] = ($this->directoryCounts[$key] ?? 0) + (int) $count;
        }

        return $this->directoryCounts;
    }

    private function currentDirectory(): ?string
    {
        return $this->path !== '' ? $this->path : null;
    }

    /**
     * @return list<int>
     */
    private function selectedIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->selected))));
    }

    /**
     * @return list<string>
     */
    private function directories(): array
    {
        return $this->directories ??= MediaDirectoryManager::existingDirectories();
    }

    private function scanner(): MediaUsageScanner
    {
        return $this->scanner ??= MediaUsageScanner::make();
    }

    /**
     * @return list<int>
     */
    private function missingIds(): array
    {
        if ($this->missingIds !== null) {
            return $this->missingIds;
        }

        $this->missingIds = [];

        Media::query()->select(['id', 'disk', 'path'])->orderBy('id')->chunk(500, function ($rows): void {
            foreach ($rows as $media) {
                if (blank($media->path) || ! Storage::disk($media->disk)->exists($media->path)) {
                    $this->missingIds[] = $media->id;
                }
            }
        });

        return $this->missingIds;
    }

    public static function formatSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 2).' GB',
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
