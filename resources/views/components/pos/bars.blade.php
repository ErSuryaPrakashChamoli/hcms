@props(['items' => [], 'label' => 'Chart', 'suffix' => '%', 'max' => 100])
@php
    $vals = array_values(array_filter(array_column($items, 'value'), fn ($v) => $v !== null));
    $top = max($max, $vals ? max($vals) : 1);
    $summary = $label.': '.collect($items)->map(fn ($i) => $i['label'].' '.($i['value'] === null ? 'no data' : $i['value'].$suffix))->implode(', ');
@endphp
{{-- Bar chart: CSS bars with direct labels; the summary is read to assistive technology. --}}
<figure {{ $attributes->class(['pos-bars']) }} role="img" aria-label="{{ $summary }}">
    @foreach ($items as $i => $item)
        <div class="pos-bar-col">
            <div class="pos-bar-track"><span class="pos-bar {{ $loop->last ? 'is-current' : '' }}" style="height: {{ $item['value'] === null ? 0 : max(4, round($item['value'] / $top * 100)) }}%"></span></div>
            <span class="pos-bar-label">{{ $item['label'] }}</span>
        </div>
    @endforeach
</figure>
