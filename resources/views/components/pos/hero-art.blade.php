{{-- Original PeopleOS illustration: dusk over layered hills (no stock imagery). Decorative. --}}
<svg {{ $attributes->class(['pos-hero-art']) }} viewBox="0 0 480 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="pos-sky" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="var(--pos-hero-sky-1)" />
            <stop offset="1" stop-color="var(--pos-hero-sky-2)" />
        </linearGradient>
        <radialGradient id="pos-sun" cx="0.5" cy="0.5" r="0.5">
            <stop offset="0" stop-color="var(--pos-hero-sun)" stop-opacity="0.95" />
            <stop offset="1" stop-color="var(--pos-hero-sun)" stop-opacity="0" />
        </radialGradient>
    </defs>
    <rect width="480" height="200" fill="url(#pos-sky)" />
    <circle cx="352" cy="92" r="70" fill="url(#pos-sun)" />
    <circle cx="352" cy="92" r="22" fill="var(--pos-hero-sun)" opacity="0.9" />
    <path d="M0 140 C 60 110, 110 118, 160 100 S 260 70, 320 96 S 420 120, 480 92 L 480 200 L 0 200 Z" fill="var(--pos-hero-hill-1)" />
    <path d="M0 160 C 80 132, 140 150, 210 128 S 330 110, 400 136 S 460 150, 480 140 L 480 200 L 0 200 Z" fill="var(--pos-hero-hill-2)" opacity="0.9" />
    <path d="M0 182 C 90 160, 170 176, 250 160 S 390 150, 480 170 L 480 200 L 0 200 Z" fill="var(--pos-hero-hill-3)" opacity="0.85" />
    <g fill="#ffffff" opacity="0.7"><circle cx="64" cy="34" r="1.4" /><circle cx="118" cy="22" r="1" /><circle cx="210" cy="40" r="1.2" /><circle cx="268" cy="18" r="1" /><circle cx="440" cy="30" r="1.3" /></g>
</svg>
