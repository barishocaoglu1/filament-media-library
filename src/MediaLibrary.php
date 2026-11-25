<?php

namespace BarisDev\MediaLibrary;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Kullanım haritasına kod üzerinden kaynak ekler (config'e alternatif / ek).
 *
 *   // AppServiceProvider::boot()
 *   MediaLibrary::track(Post::class, 'cover_image')->editedBy(PostResource::class, 'slug');
 *   MediaLibrary::track('authors', 'avatar', 'path', label: 'Yazar avatarı');
 */
class MediaLibrary
{
    /** @var list<TrackedSource> */
    protected static array $tracked = [];

    /**
     * @param  class-string<Model>|string  $source  Eloquent model sınıfı veya tablo adı
     * @param  'id'|'ids'|'path'|'json'  $type
     */
    public static function track(string $source, string $column, string $type = 'id', ?string $label = null, ?string $title = null): TrackedSource
    {
        if (! in_array($type, ['id', 'ids', 'path', 'json'], true)) {
            throw new InvalidArgumentException("Geçersiz tür: {$type} (id | ids | path | json)");
        }

        $table = is_subclass_of($source, Model::class) ? (new $source)->getTable() : $source;

        return static::$tracked[] = new TrackedSource($table, $column, $type, $label ?? $column, $title);
    }

    /**
     * Config + kod ile eklenen kaynakların birleşimi.
     *
     * @return list<array<string, mixed>>
     */
    public static function usageSources(): array
    {
        return [
            ...array_values((array) config('media_library.usage', [])),
            ...array_map(fn (TrackedSource $s): array => $s->toArray(), static::$tracked),
        ];
    }

    public static function flush(): void
    {
        static::$tracked = [];
    }
}
