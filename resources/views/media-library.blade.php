<x-filament-panels::page>
    <div
        class="ml-library"
        x-data="{
            selected: $wire.entangle('selected'),
            pageIds() {
                const el = this.$root.querySelector('[data-page-ids]');
                return el ? JSON.parse(el.dataset.pageIds) : [];
            },
            toggle(id) {
                id = Number(id);
                const i = this.selected.map(Number).indexOf(id);
                if (i === -1) { this.selected.push(id); } else { this.selected.splice(i, 1); }
            },
            isSel(id) { return this.selected.map(Number).includes(Number(id)); },
            allPageSelected() { const ids = this.pageIds(); return ids.length > 0 && ids.every((id) => this.isSel(id)); },
            togglePage() {
                const ids = this.pageIds();
                if (this.allPageSelected()) {
                    this.selected = this.selected.filter((id) => ! ids.includes(Number(id)));
                } else {
                    ids.forEach((id) => { if (! this.isSel(id)) this.selected.push(id); });
                }
            },
        }"
    >
        {{-- İstatistikler --}}
        <div class="ml-stats">
            <div class="ml-stat">
                <div class="ml-stat-icon ml-stat-icon--total"><x-filament::icon icon="heroicon-o-photo" /></div>
                <div><div class="ml-stat-number">{{ number_format($stats['total'], 0, ',', '.') }}</div><div class="ml-stat-label">Toplam dosya</div></div>
            </div>
            <div class="ml-stat">
                <div class="ml-stat-icon ml-stat-icon--used"><x-filament::icon icon="heroicon-o-check-circle" /></div>
                <div><div class="ml-stat-number">{{ number_format($stats['used'], 0, ',', '.') }}</div><div class="ml-stat-label">Kullanımda</div></div>
            </div>
            <div class="ml-stat">
                <div class="ml-stat-icon ml-stat-icon--unused"><x-filament::icon icon="heroicon-o-archive-box" /></div>
                <div><div class="ml-stat-number">{{ number_format($stats['unused'], 0, ',', '.') }}</div><div class="ml-stat-label">Kullanılmıyor (boşta)</div></div>
            </div>
            <div class="ml-stat">
                <div class="ml-stat-icon ml-stat-icon--size"><x-filament::icon icon="heroicon-o-circle-stack" /></div>
                <div><div class="ml-stat-number">{{ $stats['size'] }}</div><div class="ml-stat-label">Toplam disk boyutu</div></div>
            </div>
        </div>

        {{-- Klasör sekmeleri --}}
        {{-- Gezgin yol çubuğu --}}
        <div class="ml-explorer-bar">
            <button
                type="button"
                class="ml-explorer-up"
                wire:click="goUp"
                @disabled($path === '')
                title="Üst klasör"
            >
                <x-filament::icon icon="heroicon-o-arrow-up" class="ml-explorer-up-icon" />
            </button>

            <nav class="ml-breadcrumbs" aria-label="Klasör yolu">
                <button type="button" wire:click="openFolder('')" @class(['ml-crumb', 'is-current' => $path === ''])>
                    <x-filament::icon icon="heroicon-o-home" class="ml-crumb-icon" /> Medya
                </button>
                @foreach ($breadcrumbs as $crumb)
                    <span class="ml-crumb-sep">›</span>
                    <button
                        type="button"
                        wire:key="crumb-{{ $crumb['path'] }}"
                        wire:click="openFolder(@js($crumb['path']))"
                        @class(['ml-crumb', 'is-current' => $loop->last])
                    >{{ $crumb['name'] }}</button>
                @endforeach
            </nav>
        </div>

        {{-- Araç çubuğu --}}
        <div class="ml-toolbar">
            <div class="ml-filter-tabs">
                @foreach (['all' => 'Tümü', 'used' => 'Kullanımda', 'unused' => 'Kullanılmıyor', 'missing' => 'Diskte yok'] as $key => $label)
                    <button type="button" wire:click="setUsage('{{ $key }}')" @class(['ml-filter-tab', 'is-active' => $usage === $key])>{{ $label }}</button>
                @endforeach
            </div>

            <select wire:model.live="kind" class="ml-select" aria-label="Dosya türü">
                <option value="all">Tüm türler</option>
                <option value="image">Görseller</option>
                <option value="video">Videolar</option>
                <option value="document">Dokümanlar</option>
            </select>

            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                class="ml-search"
                placeholder="{{ $path === '' ? 'Klasör veya dosya adı ara…' : 'Bu klasörde klasör/dosya ara…' }}"
            />

            <div class="ml-toolbar-end">
                <span class="ml-result-count">
                    @if (count($childFolders) > 0){{ count($childFolders) }} klasör · @endif{{ number_format($files->total(), 0, ',', '.') }} dosya
                </span>
                @if (count($cards) > 0)
                    <button type="button" class="ml-link-btn" x-on:click="togglePage()" x-text="allPageSelected() ? 'Sayfadaki seçimi kaldır' : 'Sayfadakileri seç'"></button>
                @endif
            </div>
        </div>

        {{-- Izgara --}}
        <div class="ml-grid-wrap">
            <div wire:loading.delay class="ml-loading">Yükleniyor…</div>

            @if ($filtering)
                <p class="ml-filter-note">
                    @if (trim($search) !== '')
                        Adı eşleşen klasörler ve dosyalar {{ $path === '' ? 'tüm kütüphanede' : '"'.$path.'" ve alt klasörlerinde' }} aranıyor. Klasöre tıklayarak içine girebilirsiniz.
                    @else
                        Filtre {{ $path === '' ? 'tüm kütüphanede' : '"'.$path.'" ve alt klasörlerinde' }} uygulanıyor.
                    @endif
                </p>
            @endif

            @if (count($cards) === 0 && count($childFolders) === 0)
                <div class="ml-empty">
                    <x-filament::icon icon="heroicon-o-folder-open" class="ml-empty-icon" />
                    <p>{{ $filtering ? 'Eşleşen klasör veya dosya yok.' : 'Bu klasör boş.' }}</p>
                </div>
            @else
                <div class="ml-grid" data-page-ids="{{ json_encode(collect($cards)->pluck('id')->all()) }}">
                    @foreach ($childFolders as $dir)
                        <button
                            type="button"
                            wire:key="dir-{{ $dir['path'] }}"
                            wire:click="openFolder(@js($dir['path']))"
                            class="ml-card ml-folder-card"
                            title="{{ $dir['path'] }}/"
                        >
                            <div class="ml-folder-art">
                                <x-media-library.folder-icon :filled="$dir['files'] > 0 || $dir['folders'] > 0" />
                            </div>
                            <div class="ml-folder-label">
                                <div class="ml-folder-name">{{ $dir['name'] }}</div>
                                <div class="ml-folder-meta">
                                    @if ($dir['folders'] > 0){{ $dir['folders'] }} klasör · @endif{{ $dir['files'] > 0 ? $dir['files'].' dosya' : 'boş' }}
                                </div>
                                @if ($filtering && filled($dir['parent'] ?? null))
                                    <div class="ml-folder-meta ml-folder-parent">{{ $dir['parent'] }}/</div>
                                @endif
                            </div>
                        </button>
                    @endforeach

                    @foreach ($cards as $card)
                        @php
                            $usageTitle = collect($card['usages'])->pluck('label')->implode("\n");
                        @endphp
                        <div
                            wire:key="media-{{ $card['id'] }}"
                            class="ml-card"
                            x-bind:class="{ 'is-selected': isSel({{ $card['id'] }}) }"
                            x-on:click="toggle({{ $card['id'] }})"
                            title="{{ $card['path'] }}"
                        >
                            <span class="ml-check" aria-hidden="true"></span>

                            @if (count($card['usages']) > 0)
                                <span class="ml-used-badge" title="Kullanıldığı yerler:&#10;{{ $usageTitle }}">
                                    <x-filament::icon icon="heroicon-s-check" class="ml-used-icon" />
                                    @if (count($card['usages']) > 1)
                                        <span class="ml-used-count">{{ count($card['usages']) }}</span>
                                    @endif
                                </span>
                            @endif

                            <div class="ml-thumb">
                                @if (! $card['exists'])
                                    <div class="ml-thumb-placeholder ml-thumb-placeholder--missing">
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="ml-thumb-icon" />
                                        <span>Diskte yok</span>
                                    </div>
                                @elseif ($card['thumb'])
                                    <img src="{{ $card['thumb'] }}" alt="" loading="lazy" @class(['ml-thumb-img', 'ml-thumb-img--contain' => $card['ext'] === 'svg' || $card['ext'] === 'ico'])>
                                @else
                                    @php
                                        $docGroup = match (true) {
                                            $card['kind'] === 'video' => 'video',
                                            in_array($card['ext'], ['xlsx', 'xls', 'xlsm', 'csv', 'ods'], true) => 'excel',
                                            $card['ext'] === 'pdf' => 'pdf',
                                            in_array($card['ext'], ['doc', 'docx', 'odt', 'rtf'], true) => 'word',
                                            in_array($card['ext'], ['ppt', 'pptx', 'odp'], true) => 'slides',
                                            default => 'other',
                                        };
                                        $docIcon = [
                                            'video' => 'heroicon-o-film',
                                            'excel' => 'heroicon-o-table-cells',
                                            'slides' => 'heroicon-o-presentation-chart-bar',
                                        ][$docGroup] ?? 'heroicon-o-document-text';
                                    @endphp
                                    <div class="ml-thumb-placeholder ml-doc ml-doc--{{ $docGroup }}">
                                        <x-filament::icon :icon="$docIcon" class="ml-thumb-icon" />
                                        <span class="ml-ext">{{ $card['ext'] }}</span>
                                    </div>
                                @endif
                            </div>

                            <div class="ml-info">
                                <div class="ml-name">{{ $card['name'] }}</div>
                                <div class="ml-meta">
                                    {{ strtoupper($card['ext']) }} · {{ $card['size'] }}@if ($card['dimensions']) · {{ $card['dimensions'] }}@endif
                                </div>
                                <div class="ml-meta ml-meta--dir">{{ $card['directory'] }}/</div>
                                <div class="ml-card-actions" x-on:click.stop>
                                    @if ($card['url'])
                                        <a href="{{ $card['url'] }}" target="_blank" rel="noopener" class="ml-card-action" title="Dosyayı yeni sekmede aç">
                                            <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="ml-card-action-icon" />
                                        </a>
                                    @endif
                                    <a href="{{ $card['edit_url'] }}" class="ml-card-action" title="Bilgileri düzenle (başlık, alt metin)">
                                        <x-filament::icon icon="heroicon-o-pencil-square" class="ml-card-action-icon" />
                                    </a>
                                    @if (count($card['usages']) > 0)
                                        <span class="ml-usage-text" title="{{ $usageTitle }}">{{ count($card['usages']) }} kullanım</span>
                                    @else
                                        <span class="ml-usage-text ml-usage-text--idle">boşta</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="ml-pagination">
                    <x-filament::pagination :paginator="$files" />
                </div>
            @endif
        </div>

        {{-- Alt seçim çubuğu --}}
        <div class="ml-sel-bar" x-show="selected.length > 0" x-transition x-cloak>
            <span class="ml-sel-count"><span x-text="selected.length"></span> dosya seçildi</span>
            <button type="button" class="ml-sel-btn" x-on:click="selected = []">Seçimi temizle</button>
            <button type="button" class="ml-sel-btn ml-sel-btn--danger" wire:click="prepareDelete" wire:loading.attr="disabled">
                <x-filament::icon icon="heroicon-o-trash" class="ml-sel-icon" /> Seçilenleri sil
            </button>
        </div>

        {{-- Silme / uyarı penceresi --}}
        <x-filament::modal id="ml-delete" width="2xl">
            <x-slot name="heading">
                @if (! empty($deletePlan['blocked']))
                    Kullanımdaki dosyalar var
                @else
                    Dosyalar silinsin mi?
                @endif
            </x-slot>

            @if (! empty($deletePlan))
                <div class="ml-modal-body">
                    @if (! empty($deletePlan['blocked']))
                        <p class="ml-modal-warn">
                            Seçtiğiniz {{ $deletePlan['total'] }} dosyadan <strong>{{ count($deletePlan['blocked']) }}</strong> tanesi sitede kullanılıyor.
                            Bunlar silinirse ilgili sayfalarda görsel/dosya görünmez olur.
                        </p>
                        <ul class="ml-blocked-list">
                            @foreach ($deletePlan['blocked'] as $item)
                                <li wire:key="blocked-{{ $item['id'] }}">
                                    <div class="ml-blocked-name">{{ $item['name'] }}</div>
                                    <div class="ml-usage-chips">
                                        @foreach ($item['usages'] as $use)
                                            @if ($use['url'])
                                                <a href="{{ $use['url'] }}" target="_blank" class="ml-usage-chip">{{ $use['label'] }}</a>
                                            @else
                                                <span class="ml-usage-chip">{{ $use['label'] }}</span>
                                            @endif
                                        @endforeach
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p>Seçilen {{ $deletePlan['total'] }} dosya hiçbir yerde kullanılmıyor ve kalıcı olarak silinecek.</p>
                    @endif
                </div>
            @endif

            <x-slot name="footerActions">
                @if (! empty($deletePlan['blocked']))
                    <x-filament::button color="success" wire:click="confirmDelete(false)" :disabled="($deletePlan['unused'] ?? 0) === 0">
                        Sadece kullanılmayanları sil ({{ $deletePlan['unused'] ?? 0 }})
                    </x-filament::button>
                    <x-filament::button
                        color="danger"
                        wire:click="confirmDelete(true)"
                        wire:confirm="Kullanımdaki dosyalar da silinecek ve sitede görünmez olacak. Emin misiniz?"
                    >
                        Yine de sil ({{ $deletePlan['total'] ?? 0 }})
                    </x-filament::button>
                @else
                    <x-filament::button color="danger" wire:click="confirmDelete(false)">
                        Sil ({{ $deletePlan['total'] ?? 0 }})
                    </x-filament::button>
                @endif
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'ml-delete' })">
                    Vazgeç
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
