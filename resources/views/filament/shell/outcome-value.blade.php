{{-- One value in an outcome card (App\Filament\Support\Review\Outcome): text, chips, before → after, HTML, or "None". --}}
@if ($value['kind'] === 'chips')
    <span class="pos-outcome-chips">
        @foreach ($value['chips'] as $chip)
            <span class="pos-outcome-chip" data-tone="{{ $chip['tone'] }}">@if ($chip['tone'] === 'added')<span aria-hidden="true">+</span><span class="sr-only">Joining: </span>@elseif ($chip['tone'] === 'removed')<span aria-hidden="true">−</span><span class="sr-only">Leaving: </span>@endif{{ $chip['label'] }}</span>
        @endforeach
    </span>
@elseif ($value['kind'] === 'change')
    <span class="pos-outcome-change">
        <span class="pos-change-before">{{ $value['before'] ?? 'None' }}</span>
        <x-filament::icon icon="heroicon-m-arrow-long-right" class="pos-outcome-inline-arrow" />
        <span class="pos-change-after">{{ $value['after'] ?? 'None' }}</span>
    </span>
@elseif ($value['kind'] === 'html')
    <span class="pos-outcome-value">{{ $value['html'] }}</span>
@elseif ($value['kind'] === 'empty')
    <span class="pos-outcome-value is-empty">{{ $value['text'] }}</span>
@else
    <span class="pos-outcome-value">{{ $value['text'] }}</span>
@endif
