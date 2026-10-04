@props(['value', 'label', 'delta' => null, 'meaning' => null, 'href' => null, 'drill' => null])
{{-- PeopleMetric: one figure (value, label, change). Change is coloured by meaning, never by direction alone. --}}
@php($tag = $href ? 'a' : ($drill ? 'button' : 'div'))
<{{ $tag }} {{ $attributes->class(['pos-figure']) }} @if ($href) href="{{ $href }}" wire:navigate @endif @if ($drill) type="button" x-data x-on:click="{{ $drill }}" @endif>
    <span class="pos-figure-value">{{ $value }}</span>
    <span class="pos-figure-label">{{ $label }}</span>
    @if ($delta !== null)<span class="pos-figure-delta" @if ($meaning) data-meaning="{{ $meaning }}" @endif>{{ $delta }}</span>@endif
</{{ $tag }}>
