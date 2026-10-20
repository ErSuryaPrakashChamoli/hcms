@props(['total' => 0, 'cap' => 3, 'noun' => null])
{{--
    UX.17 progressive disclosure for phones: a long list shows its first rows (three, or one insight); the rest are one
    tap away, in place (no navigation, nothing removed). Wider screens show every row and never see the button.
--}}
@if ($total > $cap)
    <div {{ $attributes->class(['pos-phone-cap']) }} data-cap="{{ $cap }}" x-data="{ all: false }" :data-all="all.toString()">
        {{ $slot }}
        <button type="button" class="pos-phone-more" x-on:click="all = ! all" :aria-expanded="all.toString()" aria-expanded="false">
            <span x-show="! all">Show {{ $total - $cap }} more{{ $noun ? ' '.$noun : '' }}</span><span x-show="all" x-cloak>Show fewer</span>
        </button>
    </div>
@else
    {{ $slot }}
@endif
