@props(['segments' => [], 'label' => 'Breakdown', 'size' => 96, 'center' => null])
@php
    $total = max(1, array_sum(array_column($segments, 'value')));
    $r = 30; $c = 2 * M_PI * $r; $offset = 0;
    $summary = $label.': '.collect($segments)->map(fn ($s) => $s['label'].' '.$s['value'])->implode(', ');
@endphp
<svg {{ $attributes->class(['pos-donut']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 80 80" role="img" aria-label="{{ $summary }}">
    <circle cx="40" cy="40" r="{{ $r }}" class="pos-ring-track" />
    @foreach ($segments as $i => $seg)
        @php($len = $c * $seg['value'] / $total)
        @if ($seg['value'] > 0)
            <circle cx="40" cy="40" r="{{ $r }}" fill="none" stroke="var(--pos-chart-{{ $i + 1 }})" stroke-width="10" stroke-dasharray="{{ round($len, 2) }} {{ round($c, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}" transform="rotate(-90 40 40)" />
        @endif
        @php($offset += $len)
    @endforeach
    @if ($center)<text x="40" y="44" text-anchor="middle" class="pos-donut-center">{{ $center }}</text>@endif
</svg>
