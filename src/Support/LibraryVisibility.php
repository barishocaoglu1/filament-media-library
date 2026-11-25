<?php

namespace BarisDev\MediaLibrary\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Medya kütüphanesinde (sayfa + seçim penceresi) gösterilmeyecek dosya
 * türleri. Kayıtlar/dosyalar silinmez, yalnız listelerden çıkarılır.
 * Liste: config('media_library.hidden_extensions').
 */
class LibraryVisibility
{
    public static function apply(Builder $query): Builder
    {
        $hidden = self::hiddenExtensions();

        if ($hidden === []) {
            return $query;
        }

        $placeholders = implode(',', array_fill(0, count($hidden), '?'));

        return $query->whereRaw("LOWER(COALESCE(ext, '')) NOT IN ({$placeholders})", $hidden);
    }

    public static function isHidden(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::hiddenExtensions(), true);
    }

    /**
     * @return list<string>
     */
    public static function hiddenExtensions(): array
    {
        return array_values(array_map('strtolower', (array) config('media_library.hidden_extensions', [])));
    }
}
