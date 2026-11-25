<?php

namespace Tachyon\MediaLibrary;

use Awcodes\Curator\CuratorPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Panel sağlayıcısında tek satır:
 *
 *   ->plugin(MediaLibraryPlugin::make())
 *   ->plugin(MediaLibraryPlugin::make()->label('Medya')->pluralLabel('Medya Kütüphanesi')->navigationIcon('heroicon-o-photo'))
 *
 * Panelde CuratorPlugin zaten kayıtlıysa tekrar eklenmez.
 */
class MediaLibraryPlugin implements Plugin
{
    protected string $label = 'Medya';

    protected string $pluralLabel = 'Medya Kütüphanesi';

    protected string $navigationIcon = 'heroicon-o-photo';

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'media-library';
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function pluralLabel(string $pluralLabel): static
    {
        $this->pluralLabel = $pluralLabel;

        return $this;
    }

    public function navigationIcon(string $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function register(Panel $panel): void
    {
        if (! $panel->hasPlugin('curator')) {
            $panel->plugin(
                CuratorPlugin::make()
                    ->label($this->label)
                    ->pluralLabel($this->pluralLabel)
                    ->navigationIcon($this->navigationIcon)
            );
        }
    }

    public function boot(Panel $panel): void {}
}
