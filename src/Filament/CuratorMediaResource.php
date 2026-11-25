<?php

namespace Tachyon\MediaLibrary\Filament;

use Tachyon\MediaLibrary\Filament\Pages\EditMedia;
use Tachyon\MediaLibrary\Filament\Pages\ListMedia;
use Awcodes\Curator\Resources\Media\MediaResource;
use Awcodes\Curator\Resources\Media\Pages\CreateMedia;
use Awcodes\Curator\Resources\Media\Tables\MediaTable;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Vendor Curator medya kaynağını genişletir: aktif/pasif düğmesi ve özel
 * ListMedia/EditMedia sayfaları. Kullanım bilgisi MediaUsageScanner'dandır.
 *
 * Deliberately lives outside app/Filament/Resources so the panel's
 * ->discoverResources() directory scan does not also register it a
 * second time; it is only wired in via config('curator.resource.resource')
 * and the CuratorPlugin registered on the panel.
 */
class CuratorMediaResource extends MediaResource
{
    /**
     * Pinned explicitly: since this class lives outside the
     * "...\Resources\..." namespace segment (see class docblock), Filament's
     * automatic slug derivation would otherwise produce an unpredictable URL.
     */
    protected static ?string $slug = 'media';

    public static function table(Table $table): Table
    {
        $livewire = $table->getLivewire();

        $baseColumns = collect($livewire->layoutView === 'grid'
            ? MediaTable::getDefaultGridTableColumns()
            : MediaTable::getDefaultTableColumns()
        )->map(function ($column) {
            if (method_exists($column, 'getName') && $column->getName() === 'directory') {
                return $column->toggledHiddenByDefault(false);
            }

            return $column;
        })->all();

        return $table
            ->columns([
                ...$baseColumns,
                ToggleColumn::make('is_active')
                    ->label('Durum')
                    ->onColor('success')
                    ->offColor('danger'),
            ])
            ->searchable(['title', 'caption', 'description'])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->contentGrid(function () use ($livewire): ?array {
                if ($livewire->layoutView === 'grid') {
                    return [
                        'md' => 2,
                        'lg' => 3,
                        'xl' => 4,
                    ];
                }

                return null;
            })
            ->defaultPaginationPageOption(12)
            ->paginationPageOptions([6, 12, 24, 48, 'all'])
            ->recordUrl(null);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
            'create' => CreateMedia::route('/create'),
            'edit' => EditMedia::route('/{record}/edit'),
        ];
    }
}
