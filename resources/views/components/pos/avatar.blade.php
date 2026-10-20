@props(['name' => '', 'size' => 'md', 'src' => null])
@php
    $initials = collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '·';
    $tone = (crc32((string) $name) % 4) + 1;
@endphp
<span {{ $attributes->class(['pos-avatar', 'pos-avatar-'.$size]) }} data-tone="{{ $tone }}" aria-hidden="true">
    @if ($src)
        <img src="{{ $src }}" alt="" loading="lazy" />
    @else
        {{ $initials }}
    @endif
</span>
