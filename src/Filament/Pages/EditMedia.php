<?php

namespace Tachyon\MediaLibrary\Filament\Pages;

use Tachyon\MediaLibrary\Filament\CuratorMediaResource;
use Tachyon\MediaLibrary\Support\MediaDeleter;
use Tachyon\MediaLibrary\Support\MediaUsageScanner;
use Awcodes\Curator\Resources\Media\Pages\EditMedia as CuratorEditMedia;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class EditMedia extends CuratorEditMedia
{
    protected static string $resource = CuratorMediaResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $usages = MediaUsageScanner::make()->usagesFor((int) $this->record->getKey());

        if ($usages === []) {
            return new HtmlString('<span class="ml-usage-line">Bu dosya sitede hiçbir yerde kullanılmıyor.</span>');
        }

        $items = collect($usages)->map(function (array $usage): string {
            $label = e($usage['label']);

            return $usage['url']
                ? '<a class="ml-usage-chip" href="'.e($usage['url']).'">'.$label.'</a>'
                : '<span class="ml-usage-chip">'.$label.'</span>';
        })->implode('');

        return new HtmlString('<span class="ml-usage-line">Kullanıldığı yerler ('.count($usages).'):</span> <span class="ml-usage-chips">'.$items.'</span>');
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->action('save')
                ->label(trans('curator::views.panel.edit_save')),
            Action::make('preview')
                ->color('gray')
                ->url($this->record->url, shouldOpenInNewTab: true)
                ->label(trans('curator::views.panel.view')),
            Action::make('safeDelete')
                ->label('Sil')
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => MediaUsageScanner::make()->isUsed((int) $this->record->getKey())
                    ? 'Kullanımda olan dosya siliniyor'
                    : 'Dosya silinsin mi?')
                ->modalDescription(function (): string {
                    $usages = MediaUsageScanner::make()->usagesFor((int) $this->record->getKey());

                    if ($usages === []) {
                        return 'Dosya kalıcı olarak silinecek.';
                    }

                    return 'Bu dosya şu yerlerde kullanılıyor ve silinirse sitede görünmez olur: '
                        .collect($usages)->pluck('label')->implode(', ').'.';
                })
                ->modalSubmitActionLabel('Yine de sil')
                ->action(function (MediaDeleter $deleter): void {
                    $result = $deleter->delete([(int) $this->record->getKey()], force: true);

                    if ($result['deleted'] === []) {
                        Notification::make()->title('Dosya silinemedi')->danger()->send();

                        return;
                    }

                    Notification::make()->title('Dosya silindi')->success()->send();

                    $this->redirect(CuratorMediaResource::getUrl('index'));
                }),
        ];
    }
}
