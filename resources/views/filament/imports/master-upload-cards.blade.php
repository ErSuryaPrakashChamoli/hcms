{{-- Master uploads: one card per workbook this person may use (WorkbookCatalogue), shared by Data import's "Preferred
     imports" band and the Master uploads page. Each card: what it is for, its worksheets, Start and the template. The
     page that includes it answers downloadWorkbookTemplate(key). --}}
<div class="pos-mu-cards" role="list" aria-label="Master uploads">
    @foreach ($workbooks as $workbook)
        @php($shown = array_slice($workbook['sheets'], 0, 6))
        <article role="listitem" class="pos-mu-card" style="--tone: var(--pos-hue-{{ $workbook['tone'] }}); --tone-soft: var(--pos-hue-{{ $workbook['tone'] }}-bg);" wire:key="mu-card-{{ $workbook['key'] }}">
            <div class="pos-mu-card-head">
                <span class="pos-mu-card-icon" aria-hidden="true"><x-filament::icon :icon="$workbook['icon']" class="size-5" /></span>
                <div class="pos-mu-card-title">
                    <h3>{{ $workbook['label'] }}</h3>
                    <span>{{ count($workbook['sheets']) }} worksheets · {{ $workbook['companies'] }} · runs in dependency order</span>
                </div>
            </div>
            <p class="pos-mu-card-about">{{ $workbook['about'] }}</p>
            <ul class="pos-mu-card-sheets" aria-label="Worksheets">
                @foreach ($shown as $sheet)
                    <li>{{ $sheet['title'] }}</li>
                @endforeach
                @if (count($workbook['sheets']) > count($shown))
                    <li class="is-more">+{{ count($workbook['sheets']) - count($shown) }} more</li>
                @endif
            </ul>
            <div class="pos-mu-card-actions">
                <x-filament::button tag="a" :href="$workbook['url']" size="sm" icon="heroicon-m-arrow-up-tray" wire:navigate>Upload workbook</x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-down-tray" wire:click="downloadWorkbookTemplate('{{ $workbook['key'] }}')">Template</x-filament::button>
                <a class="pos-link pos-mu-card-history" href="{{ $workbook['url'] }}#mu-history" wire:navigate>Previous uploads</a>
            </div>
        </article>
    @endforeach
</div>
