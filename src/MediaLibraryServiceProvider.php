<?php

namespace Tachyon\MediaLibrary;

use Awcodes\Curator\Facades\Curator;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Tachyon\MediaLibrary\Console\ImportUntrackedMedia;
use Tachyon\MediaLibrary\Console\InstallCommand;
use Tachyon\MediaLibrary\Filament\Components\CuratorPanel;
use Tachyon\MediaLibrary\Filament\CuratorMediaResource;
use Tachyon\MediaLibrary\Filament\Pages\EditMedia;
use Tachyon\MediaLibrary\Filament\Pages\ListMedia;
use Tachyon\MediaLibrary\Filament\Schemas\MediaForm;
use Tachyon\MediaLibrary\Models\Media;

class MediaLibraryServiceProvider extends ServiceProvider
{
    public const PACKAGE = 'tachyon/filament-media-library';

    public function register(): void
    {
        // Varsayılanlar paketten gelir: projede config yayınlanmasa ya da eski bir
        // config önbelleği olsa bile anahtarlar eksik kalmaz.
        $this->mergeConfigFrom(__DIR__.'/../config/media_library.php', 'media_library');
    }

    public function boot(): void
    {
        $root = __DIR__.'/..';

        // Görünümler: kendi alanı + Curator'ın seçici (picker) görünümünün geçersiz kılınması.
        $this->loadViewsFrom($root.'/resources/views', 'media-library');
        View::prependNamespace('curator', $root.'/resources/views/curator');

        $this->loadMigrationsFrom($root.'/database/migrations');

        FilamentAsset::register([
            Css::make('media-library', $root.'/resources/dist/media-library.css'),
        ], self::PACKAGE);

        Livewire::component('curator-panel', CuratorPanel::class);

        if (config('media_library.override_curator_config', true)) {
            $this->configureCurator();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, ImportUntrackedMedia::class]);

            $this->publishes([$root.'/config/media_library.php' => config_path('media_library.php')], 'media-library-config');
            $this->publishes([$root.'/resources/views' => resource_path('views/vendor/media-library')], 'media-library-views');
            $this->publishes([$root.'/database/migrations' => database_path('migrations')], 'media-library-migrations');
        }
    }

    protected function configureCurator(): void
    {
        config([
            'curator.model' => Media::class,
            'curator.features.directory_restriction' => false,
            'curator.resource.resource' => CuratorMediaResource::class,
            'curator.resource.pages.edit' => EditMedia::class,
            'curator.resource.pages.index' => ListMedia::class,
            'curator.resource.schemas.form' => MediaForm::class,
        ]);

        // Closure: config önbelleği eskiyse bile boot çökmez.
        Curator::configure()
            ->disk(config('curator.default_disk', 'public'))
            ->directory(config('curator.default_directory') ?: 'media')
            ->visibility(config('curator.default_visibility', 'public'))
            ->acceptedFileTypes(fn (): array => (array) (config('media_library.accepted_file_types') ?: ['image/*', 'application/pdf']))
            ->maxSize(20480);
    }
}
