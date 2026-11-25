<?php

namespace Tachyon\MediaLibrary\Support;

use Tachyon\MediaLibrary\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Canlı kullanım haritası (media-library paketindeki buildUsedMap'in
 * Laravel/Curator karşılığı).
 *
 * config('media_library.usage' | 'settings' | 'seo_file') içindeki her kaynağı
 * veritabanından okur ve hangi Curator kaydının nerede kullanıldığını çıkarır.
 * Hem id ile bağlı alanları (cover_image = 66) hem de dosya yoluyla bağlı
 * alanları (dokümanlar, logolar) kapsar. Hiçbir şey yazmaz.
 */
class MediaUsageScanner
{
    /** @var array<int, list<array{label: string, link: array<string, mixed>}>>|null */
    private ?array $map = null;

    /** @var array<string, ?string> */
    private array $urlCache = [];

    /** @var array<string, list<string>> Kütüphanede kaydı olmayan ama kullanılan yollar */
    private array $unresolvedPaths = [];

    /** @var array<string, int>|null */
    private ?array $pathIndex = null;

    public static function make(): self
    {
        return app(self::class);
    }

    /**
     * @return array<int, list<array{label: string, link: array<string, mixed>}>>
     */
    public function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = [];
        $this->unresolvedPaths = [];

        foreach (\Tachyon\MediaLibrary\MediaLibrary::usageSources() as $source) {
            $this->scanTable($source);
        }

        foreach ((array) config('media_library.settings', []) as $source) {
            $this->scanSetting($source);
        }

        foreach ((array) config('media_library.seo_file', []) as $source) {
            $this->scanSeoFile($source);
        }

        return $this->map;
    }

    /**
     * @return list<int>
     */
    public function usedIds(): array
    {
        return array_keys($this->map());
    }

    public function isUsed(int $mediaId): bool
    {
        return isset($this->map()[$mediaId]);
    }

    /**
     * Linkler yalnız istenen kayıt için üretilir (tüm harita için üretmek yavaş).
     *
     * @return list<array{label: string, url: ?string}>
     */
    public function usagesFor(int $mediaId): array
    {
        return array_map(fn (array $usage): array => [
            'label' => $usage['label'],
            'url' => $this->resolveLink($usage['link']),
        ], $this->map()[$mediaId] ?? []);
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private function resolveLink(array $link): ?string
    {
        $cacheKey = json_encode($link);

        if (array_key_exists($cacheKey, $this->urlCache)) {
            return $this->urlCache[$cacheKey];
        }

        return $this->urlCache[$cacheKey] = isset($link['page'])
            ? $this->pageUrl($link['page'])
            : $this->resourceUrl($link['resource'] ?? null, $link['key'] ?? null);
    }

    /**
     * @return array<string, list<string>>
     */
    public function unresolvedPaths(): array
    {
        $this->map();

        return $this->unresolvedPaths;
    }

    public function forget(): void
    {
        $this->map = null;
        $this->pathIndex = null;
        $this->unresolvedPaths = [];
    }

    public static function normalizePath(?string $path): string
    {
        $path = trim(str_replace('\\', '/', (string) $path));

        if ($path === '') {
            return '';
        }

        if (str_contains($path, '/storage/')) {
            $path = substr($path, strpos($path, '/storage/') + strlen('/storage/'));
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function scanTable(array $source): void
    {
        $table = $source['table'];
        $column = $source['column'];

        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return;
            }

            $hasSoftDeletes = Schema::hasColumn($table, 'deleted_at');
            $titleColumn = $source['title'] ?? null;
            $routeKey = $source['route_key'] ?? 'id';
            $owner = $source['owner'] ?? null;

            $select = array_values(array_unique(array_filter([
                'id',
                $column,
                $titleColumn,
                $routeKey,
                is_array($owner) ? $owner['column'] : null,
                $hasSoftDeletes ? 'deleted_at' : null,
            ])));

            $ownerKeys = [];
            if (is_array($owner)) {
                $ownerKeys = DB::table($owner['table'])->pluck($owner['route_key'] ?? 'id', 'id')->all();
            }

            DB::table($table)
                ->select($select)
                ->whereNotNull($column)
                ->orderBy('id')
                ->chunk(200, function ($rows) use ($source, $column, $titleColumn, $routeKey, $owner, $ownerKeys, $hasSoftDeletes): void {
                    foreach ($rows as $row) {
                        $label = $source['label'];
                        $title = $titleColumn ? trim((string) ($row->{$titleColumn} ?? '')) : '';

                        if ($title !== '') {
                            $label .= ': '.mb_strimwidth($title, 0, 60, '…');
                        }

                        if ($hasSoftDeletes && filled($row->deleted_at ?? null)) {
                            $label .= ' (çöpte)';
                        }

                        $key = is_array($owner)
                            ? ($ownerKeys[$row->{$owner['column']}] ?? null)
                            : ($row->{$routeKey} ?? null);

                        $link = ['resource' => $source['edit'] ?? null, 'key' => $key];

                        foreach ($this->extract($row->{$column}, $source) as [$kind, $value]) {
                            $this->add($kind, $value, $label, $link);
                        }
                    }
                });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function scanSetting(array $source): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $payload = DB::table('settings')
                ->where('group', $source['group'])
                ->where('name', $source['name'])
                ->value('payload');

            if ($payload === null) {
                return;
            }

            $value = json_decode((string) $payload, true);
            $link = ['page' => $source['page'] ?? null];

            foreach ($this->extract($value, $source, decoded: true) as [$kind, $item]) {
                $this->add($kind, $item, $source['label'], $link);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function scanSeoFile(array $source): void
    {
        try {
            // Dosya tabanlı ayar okuyucusu projeye özgüdür (isteğe bağlı):
            // config('media_library.seo_file_reader') = [Sinif::class, 'metot'] -> ['anahtar' => değer]
            $reader = config('media_library.seo_file_reader');

            if (! is_array($reader) || ! is_callable($reader)) {
                return;
            }

            $value = $reader()[$source['key']] ?? null;
            $link = ['page' => $source['page'] ?? null];

            foreach ($this->extract($value, $source, decoded: true) as [$kind, $item]) {
                $this->add($kind, $item, $source['label'], $link);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<array{0: string, 1: int|string}>
     */
    private function extract(mixed $raw, array $source, bool $decoded = false): array
    {
        $type = $source['type'];
        $value = $raw;

        if (! $decoded && is_string($raw) && in_array($type, ['ids', 'json'], true)) {
            $value = json_decode($raw, true);
        }

        return match ($type) {
            'id' => $this->scalarIds($value),
            'ids' => $this->scalarIds($value),
            'path' => $this->paths($value),
            'json' => $this->walkJson($value, (array) ($source['keys'] ?? [])),
            default => [],
        };
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function scalarIds(mixed $value): array
    {
        $out = [];

        foreach ((array) $value as $item) {
            if (is_array($item)) {
                $item = $item['id'] ?? null;
            }

            if (is_numeric($item) && (int) $item > 0) {
                $out[] = ['id', (int) $item];
            }
        }

        return $out;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function paths(mixed $value): array
    {
        $out = [];

        foreach ((array) $value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = ['path', $item];
            }
        }

        return $out;
    }

    /**
     * Anahtarları her derinlikte arar (blocks uuid anahtarlı nesne ya da
     * liste olabilir).
     *
     * @param  array<string, string>  $keys
     * @return list<array{0: string, 1: int|string}>
     */
    private function walkJson(mixed $value, array $keys): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $child) {
            if (is_string($key) && isset($keys[$key])) {
                $found = $keys[$key] === 'path' ? $this->paths($child) : $this->scalarIds($child);
                array_push($out, ...$found);

                continue;
            }

            if (is_array($child)) {
                array_push($out, ...$this->walkJson($child, $keys));
            }
        }

        return $out;
    }

    private function add(string $kind, int|string $value, string $label, array $link): void
    {
        $id = null;

        if ($kind === 'id') {
            $id = (int) $value;
        } else {
            $path = self::normalizePath((string) $value);

            if ($path === '' || preg_match('#^https?://#i', $path)) {
                return;
            }

            $id = $this->pathIndex()[$path] ?? null;

            if ($id === null) {
                $this->unresolvedPaths[$path][] = $label;

                return;
            }
        }

        foreach ($this->map[$id] ?? [] as $existing) {
            if ($existing['label'] === $label) {
                return;
            }
        }

        $this->map[$id][] = ['label' => $label, 'link' => $link];
    }

    /**
     * @return array<string, int>
     */
    private function pathIndex(): array
    {
        return $this->pathIndex ??= Media::query()->pluck('id', 'path')->all();
    }

    private function resourceUrl(?string $resource, mixed $key): ?string
    {
        if (! $resource || blank($key)) {
            return null;
        }

        try {
            return $resource::getUrl('edit', ['record' => $key]);
        } catch (Throwable) {
            return null;
        }
    }

    private function pageUrl(?string $page): ?string
    {
        if (! $page) {
            return null;
        }

        try {
            return $page::getUrl();
        } catch (Throwable) {
            return null;
        }
    }
}
