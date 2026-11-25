<?php

namespace BarisDev\MediaLibrary\Filament\Components;

use BarisDev\MediaLibrary\Filament\MediaDirectoryManager;
use BarisDev\MediaLibrary\Models\Media;
use BarisDev\MediaLibrary\Support\LibraryVisibility;
use BarisDev\MediaLibrary\Support\MediaDeleter;
use BarisDev\MediaLibrary\Support\MediaUsageScanner;
use Awcodes\Curator\Components\Forms\Uploader;
use Awcodes\Curator\Components\Modals\CuratorPanel as BaseCuratorPanel;
use Awcodes\Curator\Enums\MimeType;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Formlardaki "Medya Kütüphanesinden Seç" penceresi.
 *
 * Vendor davranışına göre farklar (media-library paketindeki seçici gibi):
 * - Windows Gezgini gibi: en üst seviyeden açılır, klasörler ızgarada kutucuk
 *   olarak durur, tıklayınca içine girilir; her klasörde yalnız kendi dosyaları
 *   görünür. "Üst klasör" ve "Yeni klasör" vardır.
 * - Arama açık klasör ve tüm alt klasörlerinde yapılır.
 * - Yükleme o an açık olan klasöre yapılır ("Tümü"de iken varsayılan klasöre;
 *   eskiden diskin köküne gidiyordu).
 * - Arama, açık klasörle ve dosya türü kısıtıyla birlikte çalışır.
 * - Silme, kullanım kontrolüyle ve klasörü silmeden yapılır.
 */
class CuratorPanel extends BaseCuratorPanel
{
    public bool $showNewFolderForm = false;

    public string $newFolderName = '';

    public function mount(): void
    {
        parent::mount();

        // Alan belirli bir klasöre kilitli değilse "Tümü"nden başla. Seçici bir
        // başlangıç klasörü verdiyse (kayıt formu: ornek/{slug}) ve klasör
        // varsa orada açılır; kullanıcı yine de üst klasöre çıkabilir.
        if (! $this->isLimitedToDirectory) {
            $start = trim((string) $this->directory, '/');

            if ($start === '' || ! isset($this->directories[$start])) {
                $this->directory = null;
                $this->files = $this->getFiles();
            }
        }
    }

    public function getDirectories(): void
    {
        $excluded = (array) config('media_library.excluded_directories', []);

        $this->directories = collect(MediaDirectoryManager::pickerDirectories())
            ->reject(fn (array $dir): bool => in_array(explode('/', $dir['path'])[0], $excluded, true))
            ->all();
    }

    /**
     * Açık klasörün bir alt seviyesindeki klasörler. İçinde yalnız gizli
     * türler (Excel) olan klasör gösterilmez; tamamen boş klasör görünür.
     */
    public function getSubDirectories(): void
    {
        parent::getSubDirectories();

        $counts = LibraryVisibility::apply(Media::query())
            ->selectRaw('directory, COUNT(*) as c')
            ->groupBy('directory')
            ->pluck('c', 'directory')
            ->mapWithKeys(fn ($c, $dir): array => [trim((string) $dir, '/') => (int) $c]);

        $allDirs = array_keys($this->directories ?? []);
        $disk = Storage::disk($this->diskName);

        $this->subDirectories = collect($this->subDirectories ?? [])
            ->reject(function (array $dir) use ($counts, $allDirs, $disk): bool {
                $path = $dir['path'];
                $visible = $counts->filter(fn ($c, $d): bool => $d === $path || str_starts_with($d, $path.'/'))->sum();
                $hasChildren = collect($allDirs)->contains(fn ($d): bool => str_starts_with($d, $path.'/'));

                return $visible === 0 && ! $hasChildren && $disk->allFiles($path) !== [];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->all();
    }

    public function getBreadcrumbs(): void
    {
        parent::getBreadcrumbs();

        if (isset($this->breadcrumbs[0])) {
            $this->breadcrumbs[0]['label'] = 'Medya';
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('panelData')
            ->schema([
                Uploader::make('files_to_add')
                    ->hiddenLabel()
                    ->multiple()
                    ->label(trans('curator::forms.fields.file'))
                    ->preserveFilenames($this->shouldPreserveFilenames)
                    ->maxWidth($this->maxWidth)
                    ->minSize($this->minSize)
                    ->maxSize($this->maxSize)
                    ->rules($this->validationRules)
                    ->acceptedFileTypes($this->uploadAcceptedFileTypes())
                    ->disk($this->diskName)
                    ->visibility($this->visibility)
                    ->directory(fn (): string => $this->uploadDirectory())
                    ->pathGenerator($this->pathGenerator)
                    ->imageCropAspectRatio($this->imageCropAspectRatio)
                    ->imageResizeMode($this->imageResizeMode)
                    ->imageResizeTargetWidth($this->imageResizeTargetWidth)
                    ->imageResizeTargetHeight($this->imageResizeTargetHeight)
                    ->storeFileNamesIn('originalFilenames'),
            ]);
    }

    public function getFiles(int $page = 0, bool $excludeSelected = false): array
    {
        $paginator = $this->baseQuery()
            ->orderBy('created_at', $this->defaultSort)
            ->orderBy('id', $this->defaultSort)
            ->paginate($this->defaultLimit, page: $page);

        $this->currentPage = $paginator->currentPage();
        $this->mediaCount = $paginator->total();
        $this->lastPage = $paginator->lastPage();

        $this->getSubDirectories();
        $this->getBreadcrumbs();

        return collect($paginator->items())->map(fn (Media $item): array => $item->toArray())->all();
    }

    public function updatedSearch(): void
    {
        $term = trim($this->search);

        if ($term === '') {
            $this->files = $this->getFiles();

            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        // Yol aranmaz: klasör adı yolda geçtiği için klasörün tüm içeriği
        // gelirdi. Klasör adı eşleşmeleri aşağıda kutucuk olarak çıkar.
        $this->files = $this->baseQuery(recursive: true)
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhere('alt', 'like', $like)
                ->orWhere('caption', 'like', $like)
                ->orWhere('description', 'like', $like))
            ->orderBy('created_at', $this->defaultSort)
            ->limit(100)
            ->get()
            ->map(fn (Media $item): array => $item->toArray())
            ->all();

        $this->subDirectories = $this->matchingDirectories($term);

        // "Daha fazla yükle" arama sonucunda gösterilmesin
        $this->currentPage = $this->lastPage;
    }

    /**
     * Açık klasör ve altındaki klasörlerden adı eşleşenler (tire = boşluk).
     *
     * @return array<string, array<string, string>>
     */
    private function matchingDirectories(string $term): array
    {
        $normalize = fn (string $value): string => mb_strtolower(trim(preg_replace('/[\s\-_]+/u', ' ', $value)));
        $needle = $normalize($term);
        $base = trim((string) $this->directory, '/');

        return collect($this->directories ?? [])
            ->filter(fn (array $dir): bool => ($base === '' || str_starts_with($dir['path'], $base.'/'))
                && str_contains($normalize($dir['name']), $needle))
            ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
            ->take(60)
            ->all();
    }

    /** Klasöre girince (arama sonucundan da olsa) arama temizlenir. */
    public function handleDirectoryChange(string $directory): void
    {
        $this->search = '';

        parent::handleDirectoryChange($directory);
    }

    public function goToParentDirectory(): void
    {
        $current = (string) $this->directory;
        $parent = str_contains($current, '/') ? substr($current, 0, strrpos($current, '/')) : null;

        $this->handleDirectoryChange($parent ?? $this->diskName);
    }

    public function createFolder(): void
    {
        $name = trim($this->newFolderName);

        if ($name === '') {
            return;
        }

        $base = filled($this->directory) ? $this->directory.'/' : '';

        try {
            $path = MediaDirectoryManager::create($base.$name);
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }

        $this->newFolderName = '';
        $this->showNewFolderForm = false;
        $this->getDirectories();
        $this->handleDirectoryChange($path);

        Notification::make()->title('Klasör oluşturuldu')->body($path)->success()->send();
    }

    public function uploadDirectory(): string
    {
        return filled($this->directory)
            ? trim((string) $this->directory, '/')
            : MediaDirectoryManager::defaultDirectory();
    }

    public function destroyItemAction(): Action
    {
        return Action::make('destroyItem')
            ->label(trans('curator::views.panel.edit_delete'))
            ->color('danger')
            ->icon(Heroicon::Trash)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => $this->usagesOf($arguments) !== []
                ? 'Kullanımda olan dosya siliniyor'
                : 'Dosya silinsin mi?')
            ->modalDescription(function (array $arguments): string {
                $usages = $this->usagesOf($arguments);

                return $usages === []
                    ? 'Dosya hiçbir yerde kullanılmıyor ve kalıcı olarak silinecek.'
                    : 'Bu dosya şu yerlerde kullanılıyor ve silinirse sitede görünmez olur: '.collect($usages)->pluck('label')->implode(', ').'.';
            })
            ->modalSubmitActionLabel(fn (array $arguments): string => $this->usagesOf($arguments) !== [] ? 'Yine de sil' : 'Sil')
            ->action(function (array $arguments): void {
                $id = (int) ($arguments['item']['id'] ?? 0);

                if ($id === 0) {
                    return;
                }

                $result = app(MediaDeleter::class)->delete([$id], force: true);

                if ($result['deleted'] === []) {
                    Notification::make('curator_delete_error')->danger()->body(trans('curator::notifications.delete_error'))->send();

                    return;
                }

                $this->form->fill();
                $this->selected = array_values(array_filter($this->selected, fn ($item): bool => (int) ($item['id'] ?? 0) !== $id));
                $this->removeFromFiles($id);

                Notification::make('curator_delete_success')->success()->body(trans('curator::notifications.delete_success'))->send();
            });
    }

    public function render(): View
    {
        return view('media-library::curator-panel', [
            'uploadDirectory' => $this->uploadDirectory(),
        ]);
    }

    /**
     * Gezinmede yalnız açık klasörün kendi dosyaları (Windows Gezgini gibi);
     * aramada açık klasör + tüm alt klasörleri.
     */
    private function baseQuery(bool $recursive = false): Builder
    {
        $query = LibraryVisibility::apply(Media::query());
        $directory = trim((string) $this->directory, '/');

        if ($directory === '') {
            if (! $recursive) {
                $query->where(fn (Builder $q) => $q->whereNull('directory')->orWhere('directory', ''));
            }
        } elseif ($recursive && ! $this->isLimitedToDirectory) {
            $query->where(fn (Builder $q) => $q
                ->where('directory', $directory)
                ->orWhere('directory', 'like', addcslashes($directory, '%_\\').'/%'));
        } else {
            $query->where('directory', $directory);
        }

        if (filled($this->acceptedFileTypes) && ! $this->showAll && ! $this->acceptsEverything()) {
            $exact = array_values(array_filter($this->acceptedFileTypes, fn ($type): bool => ! str_contains((string) $type, '*')));
            $wildcards = array_values(array_filter($this->acceptedFileTypes, fn ($type): bool => str_contains((string) $type, '*')));

            $query->where(function (Builder $q) use ($exact, $wildcards): void {
                $q->whereIn('type', $exact ?: ['__none__']);

                foreach ($wildcards as $type) {
                    $q->orWhere('type', 'like', str_replace('*', '%', (string) $type));
                }
            });
        }

        return $query;
    }

    /**
     * Alan özel bir tür listesi vermediyse (vendor varsayılanı: tüm MIME
     * türleri) kütüphanenin güvenli listesi kullanılır.
     *
     * @return list<string>
     */
    private function uploadAcceptedFileTypes(): array
    {
        $allowed = (array) config('media_library.accepted_file_types', []);

        if ($this->acceptsEverything() || blank($this->acceptedFileTypes)) {
            return $allowed;
        }

        $filtered = array_values(array_filter($this->acceptedFileTypes, function ($type) use ($allowed): bool {
            $type = (string) $type;

            foreach ($allowed as $rule) {
                if ($rule === $type || (str_ends_with($rule, '/*') && str_starts_with($type, substr($rule, 0, -1)))) {
                    return true;
                }
            }

            return false;
        }));

        return $filtered ?: $allowed;
    }

    private function acceptsEverything(): bool
    {
        return count($this->acceptedFileTypes) >= count(MimeType::cases());
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    private function usagesOf(array $arguments): array
    {
        $id = (int) ($arguments['item']['id'] ?? 0);

        return $id > 0 ? MediaUsageScanner::make()->usagesFor($id) : [];
    }
}
