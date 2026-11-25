<?php

/*
|--------------------------------------------------------------------------
| Medya Kütüphanesi (Laravel + Filament + Curator)
|--------------------------------------------------------------------------
|
| Bu dosyayı config/media_library.php olarak kopyalayın ve 'usage' /
| 'settings' bölümlerini KENDİ projenizin tablolarına göre doldurun.
|
| "Kullanım haritası" her açılışta veritabanından canlı hesaplanır. Yeni bir
| medya alanı eklendiğinde buraya bir satır eklemek yeterlidir; boş
| bırakırsanız hiçbir dosya "kullanımda" görünmez ve güvenli silme koruması
| işlemez (her şey silinebilir görünür) — mutlaka doldurun.
|
| type:
|   id    -> kolon tek bir Curator id'si tutar (cover_image = 66)
|   ids   -> kolon JSON id listesi tutar ([61, 62, 63])
|   path  -> kolon storage yolunu tutar ("logos/x.png")
|   json  -> kolon iç içe JSON; `keys` içindeki anahtarlar her derinlikte
|            aranır. Değer türü: id | path
|
| title     -> kayıt adını veren kolon (rozet ipucunda görünür)
| edit      -> tıklanınca açılacak Filament Resource sınıfı (record = id kolonu)
| route_key -> Resource URL anahtarı (slug kullanan modeller için)
| owner     -> düzenleme sayfası başka kayda aitse (alt kayıt -> ana kayıt)
|
*/

return [

    'per_page' => 48,

    // true: paket, Curator'ın model/resource/sayfa ayarlarını çalışma anında kendisi ayarlar
    // (config/curator.php düzenlemeniz gerekmez). Kendi sınıflarınızı kullanacaksanız false yapın.
    'override_curator_config' => true,

    // Kod üzerinden de tanımlayabilirsiniz: MediaLibrary::track(Post::class, 'cover_image')

    // Bu klasörler kütüphaneye hiç alınmaz ve listelenmez (özel ekler,
    // geçici yüklemeler). Kök klasör adıyla eşleşir.
    'excluded_directories' => [
        'livewire-tmp',
    ],

    // Kütüphanede hiç gösterilmeyen uzantılar (kayıt/dosya silinmez).
    // Örn: ['xlsx', 'xls', 'csv']
    'hidden_extensions' => [],

    // Yükleme izinleri. PHP/HTML gibi çalıştırılabilir dosyalar bilinçli olarak yok.
    'accepted_file_types' => [
        'image/*',
        'image/avif',
        'image/bmp',
        'image/gif',
        'image/heic',
        'image/heif',
        'image/jpeg',
        'image/png',
        'image/svg+xml',
        'image/tiff',
        'image/vnd.microsoft.icon',
        'image/x-icon',
        'image/webp',
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ],

    // Tablo/kolon kaynakları — KENDİ projenize göre düzenleyin.
    'usage' => [
        // <media-library:usage> (media-library:install burayı doldurur; satırları elle de ekleyebilirsiniz)
        // ['table' => 'posts', 'column' => 'cover_image', 'type' => 'id', 'label' => 'Yazı kapak', 'title' => 'title', 'edit' => \App\Filament\Resources\PostResource::class, 'route_key' => 'slug'],
        // ['table' => 'posts', 'column' => 'gallery', 'type' => 'ids', 'label' => 'Yazı galeri', 'title' => 'title', 'edit' => \App\Filament\Resources\PostResource::class],
        // ['table' => 'authors', 'column' => 'avatar', 'type' => 'path', 'label' => 'Yazar avatarı', 'title' => 'name', 'edit' => \App\Filament\Resources\AuthorResource::class],
        // ['table' => 'posts', 'column' => 'blocks', 'type' => 'json', 'keys' => ['image' => 'id'], 'label' => 'Yazı bloğu', 'title' => 'title', 'edit' => \App\Filament\Resources\PostResource::class],
    ],

    // spatie/laravel-settings satırları (settings tablosu: group + name). Kullanmıyorsanız boş bırakın.
    'settings' => [
        // ['group' => 'visual', 'name' => 'site_logo', 'type' => 'id', 'label' => 'Site logosu', 'page' => \App\Filament\Pages\ManageVisualSettings::class],
    ],

    // Dosyada tutulan ayarlar (isteğe bağlı). Okuyucu: [Sinif::class, 'metot'] -> ['anahtar' => değer]
    'seo_file_reader' => null,
    'seo_file' => [
        // ['key' => 'og_image', 'type' => 'id', 'label' => 'SEO paylaşım görseli', 'page' => \App\Filament\Pages\ManageSeoSettings::class],
    ],
];
