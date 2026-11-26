{{-- Master uploads: one card per workbook this person may use (WorkbookCatalogue), shared by Data import's "Preferred
     imports" band and the Master uploads page. Each card, in its module's hue: an icon tile, the name and how many
     worksheets, the worksheets as chips (four, "+N more" opens the rest), then Upload workbook / Template and the
     clock for earlier uploads. The page that includes it answers downloadWorkbookTemplate(key). --}}
<div class="pos-mu-cards" role="list" aria-label="Master uploads">
    @foreach ($workbooks as $workbook)
        @php($shown = array_slice($workbook['sheets'], 0, 4))
        @php($hidden = count($workbook['sheets']) - count($shown))
        <article role="listitem" class="pos-mu-card" style="--tone: var(--pos-hue-{{ $workbook['tone'] }}); --tone-soft: var(--pos-hue-{{ $workbook['tone'] }}-bg);"
            aria-labelledby="mu-card-{{ $workbook['key'] }}" wire:key="mu-card-{{ $workbook['key'] }}">
            <span class="pos-mu-card-mark" aria-hidden="true"><x-filament::icon :icon="$workbook['icon']" /></span>
            <div class="pos-mu-card-head">
                <span class="pos-mu-card-icon" aria-hidden="true"><x-filament::icon :icon="$workbook['icon']" class="size-5" /></span>
                <div class="pos-mu-card-title">
                    <h3 id="mu-card-{{ $workbook['key'] }}">{{ $workbook['label'] }}</h3>
                    <span class="pos-mu-card-count">
                        <x-filament::icon icon="heroicon-m-table-cells" class="size-3.5" />
                        {{ count($workbook['sheets']) }} {{ \Illuminate\Support\Str::plural('worksheet', count($workbook['sheets'])) }}
                    </span>
                </div>
            </div>
            <ul class="pos-mu-card-sheets" id="mu-sheets-{{ $workbook['key'] }}" aria-label="Worksheets" x-data="{ all: false }">
                @foreach ($workbook['sheets'] as $sheet)
                    <li @if ($loop->index >= count($shown)) x-show="all" x-cloak @endif>{{ $sheet['title'] }}</li>
                @endforeach
                @if ($hidden > 0)
                    <li class="is-more">
                        <button type="button" x-on:click="all = ! all" :aria-expanded="all.toString()" aria-expanded="false" aria-controls="mu-sheets-{{ $workbook['key'] }}">
                            <span x-show="! all">+{{ $hidden }} more</span>
                            <span x-show="all" x-cloak>Show less</span>
                        </button>
                    </li>
                @endif
            </ul>
            <div class="pos-mu-card-actions">
                <x-filament::button class="pos-mu-card-upload" tag="a" :href="$workbook['url']" size="sm" icon="heroicon-m-arrow-up-tray" wire:navigate>Upload workbook</x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-down-tray" wire:click="downloadWorkbookTemplate('{{ $workbook['key'] }}')">Template</x-filament::button>
                <a class="pos-mu-card-history" href="{{ $workbook['url'] }}#mu-history" wire:navigate
                    title="Previous uploads" aria-label="Previous uploads of {{ $workbook['label'] }}">
                    <x-filament::icon icon="heroicon-m-clock" class="size-4" />
                </a>
            </div>
        </article>
    @endforeach
</div>
