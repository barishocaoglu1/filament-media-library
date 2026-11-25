<?php

namespace Tachyon\MediaLibrary\Support;

use Tachyon\MediaLibrary\Models\Media;
use Awcodes\Curator\Facades\Glide;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Medya Kütüphanesi'nin silme işlemi (paketteki güvenli silmenin karşılığı).
 *
 * - force=false iken kullanımdaki kayıtlar silinmez, "blocked" listesine girer.
 * - Kayıt Curator gözlemcisi ÇALIŞTIRILMADAN silinir; çünkü vendor gözlemcisi
 *   klasördeki son dosya silinince klasörü de siliyor (klasör yapısı korunur).
 * - Aynı dosyayı gösteren başka bir kayıt varsa dosya diskten silinmez.
 */
class MediaDeleter
{
    public function __construct(private MediaUsageScanner $scanner) {}

    /**
     * @param  list<int>  $ids
     * @return array{deleted: list<int>, blocked: array<int, array{name: string, usages: list<array{label: string, url: ?string}>}>, errors: list<int>}
     */
    public function delete(array $ids, bool $force = false): array
    {
        $deleted = [];
        $blocked = [];
        $errors = [];

        // Kullanım haritası ayarı yoksa (örn. eski config önbelleği) her dosya
        // "kullanılmıyor" görünür; bu durumda hiçbir şey silinmez.
        if (blank(\Tachyon\MediaLibrary\MediaLibrary::usageSources())) {
            report(new \RuntimeException('Kullanım haritası tanımlı değil (media_library.usage / MediaLibrary::track); silme durduruldu.'));

            return ['deleted' => [], 'blocked' => [], 'errors' => array_values(array_map('intval', $ids))];
        }

        $this->scanner->forget();

        foreach (Media::query()->whereIn('id', $ids)->get() as $media) {
            $usages = $this->scanner->usagesFor($media->id);

            if (! $force && $usages !== []) {
                $blocked[$media->id] = [
                    'name' => $media->getPrettyName(),
                    'usages' => $usages,
                ];

                continue;
            }

            try {
                $this->deleteOne($media);
                $deleted[] = $media->id;
            } catch (Throwable $e) {
                report($e);
                $errors[] = $media->id;
            }
        }

        $this->scanner->forget();

        return ['deleted' => $deleted, 'blocked' => $blocked, 'errors' => $errors];
    }

    private function deleteOne(Media $media): void
    {
        $path = (string) $media->path;
        $diskName = $media->disk ?: (string) config('curator.default_disk', 'public');

        Media::withoutEvents(fn () => $media->delete());

        // Eski "Kullanım Matrisi" satırları da temizlenir (tablo kalır).
        $media->usages()->delete();

        if ($path === '' || Media::query()->where('disk', $diskName)->where('path', $path)->exists()) {
            return;
        }

        $disk = Storage::disk($diskName);

        if ($disk->exists($path)) {
            $disk->delete($path);
        }

        try {
            Glide::getServer()->deleteCache($path);
        } catch (Throwable) {
            // Glide önbelleği yoksa sorun değil.
        }
    }
}
