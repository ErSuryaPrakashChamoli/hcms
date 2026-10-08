@props(['values' => [], 'labels' => [], 'label' => 'Trend', 'height' => 56, 'zero' => false])
@php
    $vals = array_values(array_map('floatval', $values));
    $n = count($vals);
    // zero: the axis starts at 0 (counts such as headcount), so one person leaving never draws as a cliff.
    $min = $n ? ($zero ? min(0, min($vals)) : min($vals)) : 0; $max = $n ? max($vals) : 0;
    $range = ($max - $min) ?: 1;
    $w = 300; $hgt = (int) $height;
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = $n > 1 ? round($i / ($n - 1) * $w, 1) : 0;
        $y = round($hgt - 6 - (($v - $min) / $range) * ($hgt - 12), 1);
        $pts[] = "$x,$y";
    }
    $line = implode(' ', $pts);
    $area = $n ? "0,$hgt ".$line." $w,$hgt" : '';
    $first = $vals[0] ?? 0; $last = $vals[$n - 1] ?? 0;
    $summary = $label.': from '.number_format($first).' to '.number_format($last).($labels ? ' ('.($labels[0] ?? '').' to '.($labels[$n - 1] ?? '').')' : '');
@endphp
{{-- Sparkline: SVG, no chart library; the text alternative states the change. --}}
<figure {{ $attributes->class(['pos-spark-fig']) }}>
    <svg viewBox="0 0 {{ $w }} {{ $hgt }}" preserveAspectRatio="none" class="pos-spark" role="img" aria-label="{{ $summary }}">
        @if ($n > 1)
            <polygon points="{{ $area }}" class="pos-spark-area" />
            <polyline points="{{ $line }}" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
        @endif
    </svg>
    @if ($labels)
        <figcaption class="pos-chart-axis"><span>{{ $labels[0] ?? '' }}</span><span>{{ $labels[$n - 1] ?? '' }}</span></figcaption>
    @endif
</figure>
