@props(['title', 'count' => null, 'sub' => null, 'link' => null, 'linkLabel' => null, 'id' => null])
@php($id ??= 'sec-'.\Illuminate\Support\Str::slug($title).'-'.substr(md5($title.($sub ?? '')), 0, 4))
{{-- PeopleSection: a titled region of a workspace. It is not a card; content decides whether it needs a surface. --}}
<section {{ $attributes->class(['pos-sec']) }} aria-labelledby="{{ $id }}">
    <header class="pos-sec-head">
        <h2 id="{{ $id }}" class="pos-sec-title">{{ $title }}@if ($count !== null)<span class="pos-sec-count">{{ $count }}</span>@endif</h2>
        @if ($link && $linkLabel)<a href="{{ $link }}" wire:navigate class="pos-link">{{ $linkLabel }} <span aria-hidden="true">→</span></a>@endif
        {{ $actions ?? '' }}
    </header>
    @if ($sub)<p class="pos-sec-sub -mt-2">{{ $sub }}</p>@endif
    {{ $slot }}
</section>
