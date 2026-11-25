<?php

namespace BarisDev\MediaLibrary\Filament\Actions;

use BarisDev\MediaLibrary\Filament\MediaDirectoryManager;
use Awcodes\Curator\Actions\MultiUploadAction as CuratorMultiUploadAction;
use Awcodes\Curator\Components\Forms\Uploader;
use Awcodes\Curator\Facades\Curator;
use Awcodes\Curator\Models\Media;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\App;

class MultiUploadAction extends CuratorMultiUploadAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([
            Select::make('directory')
                ->label('Klasör')
                ->options(fn (): array => MediaDirectoryManager::options())
                ->default(fn (): string => MediaDirectoryManager::defaultDirectory())
                ->searchable()
                ->preload()
                ->live()
                ->required()
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
            Uploader::make('files')
                ->acceptedFileTypes(Curator::getAcceptedFileTypes())
                ->directory(fn (Get $get): string => filled($get('directory'))
                    ? ltrim((string) $get('directory'), '/')
                    : MediaDirectoryManager::defaultDirectory())
                ->disk(Curator::getDiskName())
                ->label(trans('curator::forms.multi_upload.modal_file_label'))
                ->minSize(Curator::getMinSize())
                ->maxSize(Curator::getMaxSize())
                ->multiple()
                ->panelLayout('grid')
                ->preserveFilenames(Curator::shouldPreserveFilenames())
                ->required()
                ->visibility(Curator::getVisibility())
                ->storeFileNamesIn('originalFilename')
                ->imageCropAspectRatio(Curator::getImageCropAspectRatio())
                ->imageResizeMode(Curator::getImageResizeMode())
                ->imageResizeTargetWidth(Curator::getImageResizeTargetWidth())
                ->imageResizeTargetHeight(Curator::getImageResizeTargetHeight()),
        ])->action(function (array $data): void {
            foreach ($data['files'] as $item) {
                $item['exif'] = empty($item['exif']) ? null : Curator::sanitizeExif($item['exif']);
                $item['title'] = pathinfo((string) ($data['originalFilename'][$item['path']] ?? null), PATHINFO_FILENAME);

                tap(
                    App::make(Media::class)->create($item),
                    fn (Media $media): string => $media->getPrettyName(),
                );
            }
        });
    }
}
