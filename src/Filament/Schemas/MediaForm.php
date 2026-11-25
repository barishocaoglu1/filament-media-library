<?php

namespace Tachyon\MediaLibrary\Filament\Schemas;

use Tachyon\MediaLibrary\Filament\MediaDirectoryManager;
use Awcodes\Curator\Components\Forms\Uploader;
use Awcodes\Curator\Resources\Media\Schemas\MediaForm as CuratorMediaForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

class MediaForm extends CuratorMediaForm
{
    public static function getAdditionalInformationFormSchema(): array
    {
        return [
            Select::make('directory')
                ->label('Klasör')
                ->options(fn (): array => MediaDirectoryManager::options())
                ->default(fn (): string => MediaDirectoryManager::defaultDirectory())
                ->searchable()
                ->preload()
                ->live()
                ->required()
                ->dehydrated()
                ->disabledOn('edit')
                ->helperText('Yüklenen dosya bu klasöre kaydedilir. Listeden seçin veya yeni klasör oluşturun.')
                ->createOptionForm([
                    TextInput::make('name')
                        ->label('Klasör adı')
                        ->required()
                        ->maxLength(MediaDirectoryManager::MAX_NAME_LENGTH)
                        ->helperText('Örn: ornek veya ornek/{slug}. En fazla 255 karakter. Slug kısaltılmaz.'),
                ])
                ->createOptionUsing(fn (array $data): string => MediaDirectoryManager::create($data['name']))
                ->createOptionModalHeading('Yeni Klasör Oluştur')
                ->createOptionAction(fn ($action) => $action
                    ->label('Yeni Klasör Oluştur')
                    ->modalSubmitActionLabel('Oluştur')),
            ...array_map(
                fn ($component) => self::lockFileName($component),
                parent::getAdditionalInformationFormSchema(),
            ),
        ];
    }

    /**
     * Curator'ın gözlemcisi "name" değişince dosyayı diskte yeni ada taşır
     * (ve slug'a çevirir). Dosya yoluyla bağlı dokümanlar/logolar kırılmasın
     * diye ad alanı salt okunur yapılır; başlık, alt metin vb. düzenlenebilir.
     */
    private static function lockFileName(mixed $component): mixed
    {
        if ($component instanceof TextInput && $component->getName() === 'name') {
            return $component
                ->label('Dosya adı')
                ->disabled()
                ->dehydrated(false)
                ->required(false)
                ->helperText('Dosya adı değiştirilemez; değişirse sitedeki bağlantılar kırılır. Görünen adı "Başlık" alanından değiştirin.');
        }

        return $component;
    }

    public static function getUploaderField(): Uploader
    {
        return parent::getUploaderField()
            ->directory(function (Get $get): string {
                $directory = $get('directory');

                if (filled($directory)) {
                    return ltrim((string) $directory, '/');
                }

                return MediaDirectoryManager::defaultDirectory();
            });
    }
}
