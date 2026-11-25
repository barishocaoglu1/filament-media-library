<?php

use Illuminate\Database\Eloquent\Model;
use Tachyon\MediaLibrary\MediaLibrary;

class FakePost extends Model
{
    protected $table = 'fake_posts';
}

it('merges package config defaults', function () {
    expect(config('media_library.per_page'))->toBe(48)
        ->and(config('media_library.override_curator_config'))->toBeTrue();
});

it('tracks a source by table name', function () {
    MediaLibrary::track('authors', 'avatar', 'path', label: 'Yazar avatarı');

    expect(MediaLibrary::usageSources())->toContain([
        'table' => 'authors',
        'column' => 'avatar',
        'type' => 'path',
        'label' => 'Yazar avatarı',
    ]);
});

it('resolves the table from an Eloquent model class', function () {
    MediaLibrary::track(FakePost::class, 'cover_image')->editedBy('App\Filament\Resources\PostResource', 'slug');

    expect(MediaLibrary::usageSources())->toContain([
        'table' => 'fake_posts',
        'column' => 'cover_image',
        'type' => 'id',
        'label' => 'cover_image',
        'edit' => 'App\Filament\Resources\PostResource',
        'route_key' => 'slug',
    ]);
});

it('supports json keys and owners', function () {
    MediaLibrary::track('posts', 'blocks', 'json')->keys(['image' => 'id'])->ownedBy('post_id', 'posts', 'slug');

    $source = collect(MediaLibrary::usageSources())->firstWhere('column', 'blocks');

    expect($source['keys'])->toBe(['image' => 'id'])
        ->and($source['owner'])->toBe(['column' => 'post_id', 'table' => 'posts', 'route_key' => 'slug']);
});

it('rejects an invalid type', function () {
    MediaLibrary::track('posts', 'cover', 'banana');
})->throws(InvalidArgumentException::class);

it('combines config usage with tracked sources', function () {
    config(['media_library.usage' => [['table' => 'pages', 'column' => 'image', 'type' => 'id', 'label' => 'Sayfa']]]);
    MediaLibrary::track('authors', 'avatar', 'path');

    expect(MediaLibrary::usageSources())->toHaveCount(2);
});
