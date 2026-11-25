<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use BarisDev\MediaLibrary\Filament\CuratorMediaResource;
use BarisDev\MediaLibrary\Models\Media;

it('configures curator to use the package classes', function () {
    expect(config('curator.model'))->toBe(Media::class)
        ->and(config('curator.resource.resource'))->toBe(CuratorMediaResource::class)
        ->and(config('curator.features.directory_restriction'))->toBeFalse();
});

it('registers package views', function () {
    expect(View::exists('media-library::media-library'))->toBeTrue()
        ->and(View::exists('media-library::curator-panel'))->toBeTrue();
});

it('overrides the curator picker view', function () {
    $path = View::getFinder()->find('curator::components.forms.picker');

    expect(normalizePath($path))->toContain('resources/views/curator/components/forms/picker.blade.php')
        ->and(normalizePath($path))->not->toContain('/vendor/awcodes/');
});

it('registers console commands', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('media-library:install')->toContain('media:import-untracked');
});

it('can be disabled from touching curator config', function () {
    expect(config('media_library.override_curator_config'))->toBeTrue();
});

function normalizePath(string $p): string
{
    return str_replace(chr(92), '/', $p);
}

it('renders the folder icon component used by the views', function () {
    $empty = Blade::render('<x-media-library.folder-icon class="x" />');
    $filled = Blade::render('<x-media-library.folder-icon :filled="true" />');

    expect($empty)->toContain('ml-folder-svg')->toContain('class="ml-folder-svg x"')->not->toContain('ml-folder-paper')
        ->and($filled)->toContain('ml-folder-paper');
});
