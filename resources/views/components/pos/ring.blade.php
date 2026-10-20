@props(['value' => 0, 'total' => 0, 'label' => '', 'size' => 64])
@php
    $pct = $total > 0 ? max(0, min(1, $value / $total)) : 0;
    $r = 26; $c = 2 * M_PI * $r;
@endphp
{{-- Progress ring: SVG with a text alternative. --}}
<svg {{ $attributes->class(['pos-ring']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 64 64" role="img" aria-label="{{ $label }}: {{ rtrim(rtrim(number_format($value, 1), '0'), '.') }} of {{ rtrim(rtrim(number_format($total, 1), '0'), '.') }}">
    <circle cx="32" cy="32" r="{{ $r }}" class="pos-ring-track" />
    <circle cx="32" cy="32" r="{{ $r }}" class="pos-ring-value" stroke-dasharray="{{ round($c * $pct, 2) }} {{ round($c, 2) }}" transform="rotate(-90 32 32)" />
</svg>
