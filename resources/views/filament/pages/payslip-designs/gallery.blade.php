{{-- The five designs (and the Word layout once uploaded) with a live preview each; a click chooses one. --}}
<div class="pos-psd-gallery" role="radiogroup" aria-label="Payslip design">
    @foreach ($cards as $card)
        <button type="button" role="radio" aria-checked="{{ $selected === $card['key'] ? 'true' : 'false' }}"
            class="pos-psd-card" data-design-card="{{ $card['key'] }}" @if ($selected === $card['key']) data-selected @endif
            wire:click="$set('data.layout', '{{ $card['key'] }}')">
            <span class="pos-psd-thumb" aria-hidden="true">
                @if ($card['html'])
                    <iframe srcdoc="{{ $card['html'] }}" tabindex="-1" loading="lazy" title="{{ $card['name'] }}"></iframe>
                @else
                    <span class="pos-psd-word"><x-filament::icon icon="heroicon-o-document-text" class="size-10" /><span>Word (.docx)</span></span>
                @endif
            </span>
            <span class="pos-psd-name">
                <span>{{ $card['name'] }}</span>
                @if ($selected === $card['key'])<x-filament::icon icon="heroicon-m-check-circle" class="pos-psd-tick size-5" />@endif
            </span>
            <span class="pos-psd-about">{{ $card['about'] }}</span>
        </button>
    @endforeach
</div>
