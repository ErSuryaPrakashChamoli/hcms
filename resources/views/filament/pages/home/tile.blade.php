{{--
    Home: one headline figure as a tile. Its icon sits on a wayfinding hue (never a status: the words carry the meaning);
    a value with a meaning (e.g. exceptions to resolve) is coloured by that meaning and says so in its note.
    Expects $f: value, label, optional icon, tone (HomeBlocks KPI tone) or hue, url, note, meaning; optional $class.
--}}
@php
    $hue = $f['hue'] ?? (['indigo' => 'iris', 'emerald' => 'green', 'rose' => 'coral', 'sky' => 'blue', 'amber' => 'gold', 'violet' => 'plum', 'teal' => 'teal'][$f['tone'] ?? ''] ?? 'slate');
    $href = $f['url'] ?? null;
    $tag = $href ? 'a' : 'div';
    // Whole counts read with separators (1,164); decimals, percentages and words stay as given
    $value = is_int($f['value']) || (is_string($f['value']) && ctype_digit($f['value'])) ? number_format((int) $f['value']) : $f['value'];
@endphp
<{{ $tag }} @class(['pos-home-tile', $class ?? null]) data-hue="{{ $hue }}" @if ($href) href="{{ $href }}" wire:navigate @endif>
    <span class="pos-home-tile-icon" aria-hidden="true"><x-filament::icon :icon="$f['icon'] ?? 'heroicon-o-chart-bar'" class="size-5" /></span>
    <span class="pos-home-tile-value" @if (! empty($f['meaning'])) data-meaning="{{ $f['meaning'] }}" @endif>{{ $value }}</span>
    <span class="pos-home-tile-label">{{ $f['label'] }}</span>
    @if (! empty($f['note']))<span class="pos-home-tile-note" @if (! empty($f['meaning'])) data-meaning="{{ $f['meaning'] }}" @endif>{{ $f['note'] }}</span>@endif
    @if ($href)<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-home-tile-go size-4" aria-hidden="true" />@endif
</{{ $tag }}>
