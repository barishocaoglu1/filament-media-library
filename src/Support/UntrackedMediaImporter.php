<?php

namespace Tachyon\MediaLibrary\Support;

use Tachyon\MediaLibrary\Models\Media;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Diskte olup Curator kütüphanesinde kaydı olmayan dosyaları kütüphaneye
 * KAYIT olarak ekler. Dosyaya dokunmaz: taşımaz, yeniden adlandırmaz,
 * dönüştürmez. Kayıt yolu = dosyanın bugünkü yolu.
 */
class UntrackedMediaImporter
{
    /**
     * @return array{importable: list<string>, skipped: array<string, string>}
     */
    public function scan(): array
    {
        $disk = Storage::disk($this->diskName());
        $known = Media::query()->pluck('id', 'path')->all();

        $importable = [];
        $skipped = [];

        foreach ($disk->allFiles() as $path) {
            $path = str_replace('\\', '/', $path);

            // Excel gibi kütüphanede gösterilmeyen türler kütüphaneye alınmaz.
            if (isset($known[$path]) || $this->isExcluded($path) || LibraryVisibility::isHidden($path)) {
                continue;
            }

            if (pathinfo($path, PATHINFO_EXTENSION) === '') {
                $skipped[$path] = 'uzantısız dosya';

                continue;
            }

            $importable[] = $path;
        }

        sort($importable);

        return ['importable' => $importable, 'skipped' => $skipped];
    }

    /**
     * @return array{imported: int, failed: array<string, string>, skipped: array<string, string>}
     */
    public function import(): array
    {
        $scan = $this->scan();
        $disk = Storage::disk($this->diskName());
        $imported = 0;
        $failed = [];

        foreach ($scan['importable'] as $path) {
            try {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $name = pathinfo($path, PATHINFO_FILENAME);
                $directory = trim(str_replace('\\', '/', pathinfo($path, PATHINFO_DIRNAME)), './');
                $mime = $disk->mimeType($path) ?: 'application/octet-stream';

                $width = null;
                $height = null;

                if (str_starts_with($mime, 'image/') && $ext !== 'svg') {
                    $size = @getimagesize($disk->path($path));

                    if (is_array($size)) {
                        [$width, $height] = $size;
                    }
                }

                // Observer'ları çalıştırma: Curator'ın "creating" gözlemcisi
                // dosya yüklemesi bekler; burada dosya zaten yerinde.
                Media::withoutEvents(fn () => Media::query()->create([
                    'disk' => $this->diskName(),
                    'directory' => $directory === '' ? null : $directory,
                    'visibility' => 'public',
                    'name' => $name,
                    'path' => $path,
                    'width' => $width,
                    'height' => $height,
                    'size' => $disk->size($path),
                    'type' => $mime,
                    'ext' => $ext,
                    'title' => $name,
                    'is_active' => true,
                ]));

                $imported++;
            } catch (Throwable $e) {
                $failed[$path] = $e->getMessage();
            }
        }

        return ['imported' => $imported, 'failed' => $failed, 'skipped' => $scan['skipped']];
    }

    public function isExcluded(string $path): bool
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $root = explode('/', $path)[0] ?? '';

        if ($root === '' || str_starts_with(basename($path), '.')) {
            return true;
        }

        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '.')) {
                return true;
            }
        }

        return in_array($root, (array) config('media_library.excluded_directories', []), true);
    }

    private function diskName(): string
    {
        return (string) config('curator.default_disk', 'public');
    }
}
