<?php

namespace Tachyon\MediaLibrary\Models;

use Awcodes\Curator\Models\Media as CuratorMedia;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Curator Media modelini genişletir: aktif/pasif bayrağı (is_active) ve
 * vendor'un "temporary URL desteklenmiyor" çökmesine karşı yedekli url().
 * Kullanım bilgisi bu modelde tutulmaz; MediaUsageScanner canlı hesaplar.
 */
class Media extends CuratorMedia
{
    protected $fillable = [
        'disk',
        'directory',
        'visibility',
        'name',
        'path',
        'width',
        'height',
        'size',
        'type',
        'ext',
        'alt',
        'title',
        'description',
        'caption',
        'exif',
        'curations',
        'file',
        'is_active',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
        'curations' => 'array',
        'exif' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Vendor ile aynı mantık; tek fark: dosya diskte yoksa vendor geçici URL
     * üretmeye çalışıp "temporary URL desteklenmiyor" hatasıyla sayfayı
     * çökertiyordu. Bu durumda düz storage URL'si döner. Çalışan durumlarda
     * çıktı birebir aynıdır (sitedeki görsel adresleri değişmez).
     */
    public function url(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $storage = Storage::disk($this->disk);

                try {
                    $isPrivate = $storage->getVisibility($this->path) === 'private';
                } catch (Throwable) {
                    $isPrivate = true;
                }

                if (! $isPrivate) {
                    return $storage->url($this->path);
                }

                try {
                    return $storage->temporaryUrl($this->path, now()->addMinutes(5));
                } catch (Throwable) {
                    return $storage->url($this->path);
                }
            },
        );
    }
}
