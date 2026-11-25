# Filament Media Library

Laravel + Filament + [Curator](https://github.com/awcodes/filament-curator) için medya kütüphanesi eklentisi.

- Klasör gezintisi (Gezgin benzeri), arama, istatistik, kart görünümü
- **Kullanım takibi:** hangi görselin hangi kayıtta kullanıldığı canlı hesaplanır
- **Güvenli silme:** kullanımdaki dosya silinmez / uyarı verilir
- Çoklu yükleme, klasör oluşturma, dosya adı kilidi (bağlantılar kırılmasın diye)
- Diskte olup kaydı olmayan dosyaları içe aktarma (`media:import-untracked`)
- Uzantı gizleme ve klasör hariç tutma

## Gereksinimler
PHP 8.2+, Laravel 12/13, Filament 5.x, `awcodes/filament-curator` 5.x

## Kurulum
```bash
composer require baris-dev/filament-media-library
php artisan media-library:install
```
Kurulum komutu config'i yayınlar, Curator tablosu yoksa `curator:install` çalıştırır, `is_active`
migration'ını (Curator'ınkinden sonra çalışacak tarihle) yayınlayıp migrate eder, asset'leri günceller ve
veritabanınızda medya tutan kolonları (`image`, `logo`, `cover`, `gallery`...) bulup kullanım haritasına eklemeyi önerir.

> `php artisan vendor:publish --provider="Awcodes\Curator\CuratorServiceProvider"` ile Curator'ın görünümlerini
> yayınlamayın: `resources/views/vendor/curator` altındaki dosyalar paketin seçici (picker) görünümünü ezer.

Panel sağlayıcınıza tek satır ekleyin:
```php
->plugin(\BarisDev\MediaLibrary\MediaLibraryPlugin::make())
// isteğe bağlı: ->plugin(MediaLibraryPlugin::make()->label('Medya')->pluralLabel('Medya Kütüphanesi')->navigationIcon('heroicon-o-photo'))
```
`config/curator.php` veya `AppServiceProvider` düzenlemek **gerekmez**; paket Curator'ı kendisi yapılandırır
(kapatmak için `media_library.override_curator_config => false`).

## Kendi projenize uyarlama (kullanım haritası)
Medya kullanan her tablo/kolon bir kez tanımlanır. Tanımlamazsanız o alandaki dosyalar "kullanılmıyor" görünür ve silinebilir.

**1) Kodla** (örn. `AppServiceProvider::boot()`):
```php
use BarisDev\MediaLibrary\MediaLibrary;

MediaLibrary::track(Post::class, 'cover_image')->editedBy(PostResource::class, 'slug');
MediaLibrary::track(Post::class, 'gallery', 'ids', label: 'Yazı galeri');
MediaLibrary::track('authors', 'avatar', 'path');
MediaLibrary::track(Post::class, 'blocks', 'json')->keys(['image' => 'id']);
```

**2) Veya config'le** (`config/media_library.php` → `usage`). `media-library:install` bu listeyi sizin için doldurabilir.

| type | Kolon ne tutar |
|---|---|
| `id` | tek Curator id'si |
| `ids` | JSON id listesi |
| `path` | storage yolu |
| `json` | iç içe JSON; `keys` ile anahtarlar (`id` veya `path`) |

`spatie/laravel-settings` değerleri için `settings`, dosyada tutulan ayarlar için `seo_file` / `seo_file_reader` bölümleri vardır.

## Özelleştirme
```bash
php artisan vendor:publish --tag=media-library-config
php artisan vendor:publish --tag=media-library-views
php artisan vendor:publish --tag=media-library-migrations
```

## Mevcut dosyaları içe aktarma
```bash
php artisan media:import-untracked --dry-run   # önce listele
php artisan media:import-untracked             # sonra ekle
```
Dosyalara dokunmaz, yalnız `curator` kaydı ekler. Canlıda önce `SELECT MAX(id) FROM curator` not alın;
geri alma: `DELETE FROM curator WHERE id > <not>`.

## Notlar
- Curator, `name` değişince dosyayı diskte taşır; bu yüzden dosya adı kilitlidir, silme `MediaDeleter` üzerinden yapılır.
- PDF/Excel görünmesin istiyorsanız `hidden_extensions`; özel ekler için `excluded_directories` kullanın.
- Yükleme izinleri `accepted_file_types` ile ayarlanır (çalıştırılabilir dosyalar bilinçli olarak yok).
- Paket config varsayılanlarını kendisi birleştirir; eski config önbelleği boot'u çökertmez. Yine de dağıtımda `php artisan config:clear` iyi pratiktir.

## Lisans
MIT
