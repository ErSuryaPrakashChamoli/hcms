{{-- The Home hero's artwork: people orbiting one core. Decoration only (hidden from assistive technology, no data). --}}
<svg class="pos-home-hero-art" viewBox="0 0 480 480" aria-hidden="true" focusable="false">
    <circle class="pos-hero-halo" cx="240" cy="240" r="120" />
    <circle class="pos-hero-ring" cx="240" cy="240" r="78" />
    <circle class="pos-hero-ring" cx="240" cy="240" r="138" />
    <g class="pos-hero-orbit">
        <circle class="pos-hero-ring pos-hero-ring-dash" cx="240" cy="240" r="198" />
        <circle class="pos-hero-node" cx="411.5" cy="141" r="3.5" />
        <circle class="pos-hero-node-iris" cx="45" cy="274.4" r="4.5" />
        <circle class="pos-hero-node" cx="222.7" cy="437.2" r="3" />
    </g>
    <path class="pos-hero-link" d="M240 240 L110.3 192.8 M240 240 L309 120.5 M240 240 L309 359.5" />
    <circle class="pos-hero-node" cx="307.5" cy="279" r="3.5" />
    <circle class="pos-hero-node-iris" cx="213.3" cy="166.7" r="4" />
    <circle class="pos-hero-node" cx="172.5" cy="279" r="2.5" />
    <circle class="pos-hero-gold" cx="134.3" cy="328.7" r="6" />
    @foreach ([[110.3, 192.8], [309, 120.5], [309, 359.5]] as [$x, $y])
        <circle class="pos-hero-avatar" cx="{{ $x }}" cy="{{ $y }}" r="16" />
        <circle class="pos-hero-person" cx="{{ $x }}" cy="{{ $y - 3.5 }}" r="4.5" />
        <path class="pos-hero-person" d="M{{ $x - 7.5 }} {{ $y + 9.5 }} a7.5 6.5 0 0 1 15 0 z" />
    @endforeach
    <circle class="pos-hero-core" cx="240" cy="240" r="30" />
    <circle class="pos-hero-gold" cx="240" cy="240" r="11" />
</svg>
