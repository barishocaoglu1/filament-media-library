<?php

namespace BarisDev\MediaLibrary\Filament;

use BarisDev\MediaLibrary\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Curator v5 infers folders from the media.directory column and the disk;
 * it has no native "create folder" action. This helper is the single place
 * that lists, sanitizes and creates those directories.
 */
class MediaDirectoryManager
{
    public const MAX_NAME_LENGTH = 255;

    /**
     * @var list<string>
     */
    private const IGNORED_DIRECTORIES = [
        'livewire-tmp',
    ];

    public static function disk(): string
    {
        return (string) config('curator.default_disk', 'public');
    }

    public static function defaultDirectory(): string
    {
        $directory = config('curator.default_directory');

        return filled($directory) ? ltrim((string) $directory, '/') : 'media';
    }

    public static function ensureDefaultDirectory(): void
    {
        Storage::disk(self::disk())->makeDirectory(self::defaultDirectory());
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        self::ensureDefaultDirectory();

        return collect(self::existingDirectories())
            ->push(self::defaultDirectory())
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $directory): array => [$directory => $directory])
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function existingDirectories(): array
    {
        $fromDatabase = Media::query()
            ->whereNotNull('directory')
            ->where('directory', '!=', '')
            ->distinct()
            ->pluck('directory')
            ->all();

        $fromDisk = collect(Storage::disk(self::disk())->allDirectories())
            ->reject(fn (string $directory): bool => self::shouldIgnore($directory))
            ->all();

        return collect($fromDatabase)
            ->merge($fromDisk)
            ->map(fn (string $directory): string => trim(str_replace('\\', '/', $directory), '/'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public static function create(string $name): string
    {
        $path = self::normalize($name);

        if ($path === '') {
            throw ValidationException::withMessages([
                'name' => 'Geçerli bir klasör adı girin. Sadece harf, rakam, tire ve alt klasör için / kullanın.',
            ]);
        }

        Storage::disk(self::disk())->makeDirectory($path);

        return $path;
    }

    public static function normalize(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');

        if ($name === '' || str_contains($name, '..')) {
            return '';
        }

        return collect(explode('/', $name))
            ->map(fn (string $segment): string => Str::slug($segment))
            ->filter()
            ->implode('/');
    }

    /**
     * Shape expected by Curator's picker breadcrumbs / subdirectory list.
     *
     * @return array<string, array{label: string, name: string, path: string, parent_path: string}>
     */
    public static function pickerDirectories(): array
    {
        $directories = [];

        foreach (self::existingDirectories() as $item) {
            $segments = explode('/', $item);
            $name = array_pop($segments) ?: $item;

            $directories[$item] = [
                'label' => Str::of($name)->replace('-', ' ')->title()->toString(),
                'name' => $name,
                'path' => $item,
                'parent_path' => implode('/', $segments),
            ];
        }

        foreach ($directories as $directory) {
            if (filled($directory['parent_path']) && ! array_key_exists($directory['parent_path'], $directories)) {
                $parent = $directory['parent_path'];

                $directories[$parent] = [
                    'label' => Str::of($parent)->afterLast('/')->replace('-', ' ')->title()->toString(),
                    'name' => Str::of($parent)->afterLast('/')->toString(),
                    'path' => $parent,
                    'parent_path' => str_contains($parent, '/')
                        ? Str::beforeLast($parent, '/')
                        : '',
                ];
            }
        }

        return $directories;
    }

    private static function shouldIgnore(string $directory): bool
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');

        if ($directory === '' || str_starts_with($directory, '.')) {
            return true;
        }

        foreach (self::IGNORED_DIRECTORIES as $ignored) {
            if ($directory === $ignored || str_starts_with($directory, $ignored.'/')) {
                return true;
            }
        }

        return false;
    }
}
