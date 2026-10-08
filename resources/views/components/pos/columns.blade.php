@props(['labels' => [], 'series' => [], 'label' => 'Chart'])
@php
    // series: [['name' => 'Joined', 'values' => [...], 'color' => 1], ...]
    $max = max(1, ...array_map(fn ($s) => max($s['values'] ?: [0]), $series ?: [['values' => [0]]]));
    $summary = $label.'. '.collect($series)->map(fn ($s) => $s['name'].': '.implode(', ', array_map(fn ($l, $v) => $l.' '.$v, $labels, $s['values'])))->implode('. ');
@endphp
{{-- Grouped columns with a legend; a full text alternative is given to assistive technology. --}}
<figure {{ $attributes->class(['pos-columns']) }}>
    <div class="pos-columns-plot" role="img" aria-label="{{ $summary }}">
        @foreach ($labels as $i => $l)
            <div class="pos-columns-group">
                <div class="pos-columns-bars">
                    @foreach ($series as $s)
                        <span style="height: {{ round(($s['values'][$i] ?? 0) / $max * 100) }}%; background: var(--pos-chart-{{ $s['color'] ?? ($loop->index + 1) }})" title="{{ $s['name'] }} {{ $l }}: {{ $s['values'][$i] ?? 0 }}"></span>
                    @endforeach
                </div>
                <span class="pos-bar-label">{{ \Illuminate\Support\Str::before($l, ' ') }}</span>
            </div>
        @endforeach
    </div>
    <figcaption class="pos-legend pos-legend-inline">
        @foreach ($series as $s)<span><i style="background: var(--pos-chart-{{ $s['color'] ?? ($loop->index + 1) }})"></i>{{ $s['name'] }}</span>@endforeach
    </figcaption>
</figure>
