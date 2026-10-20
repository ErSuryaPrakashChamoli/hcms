@props(['id' => null, 'name', 'sub' => null, 'size' => 'xs', 'avatar' => true])
{{--
    PeoplePerson: how a person is shown anywhere in PeopleOS. Hover or keyboard focus shows the peek
    (directory fields); click or Enter opens the person drawer; the drawer and peek link to the Employee 360.
    Without an id (someone the viewer may not open) it is plain text.
--}}
@if ($id)
    <button type="button" {{ $attributes->class(['pos-person']) }} data-person="{{ $id }}"
        x-data x-on:click.stop="$dispatch('pos-peek-hide'); $dispatch('pos-drawer-open', { type: 'person', id: {{ (int) $id }} })">
        @if ($avatar)<x-pos.avatar :name="$name" :size="$size" />@endif
        <span class="pos-person-name">{{ $name }}@if ($sub)<span class="pos-person-sub"> · {{ $sub }}</span>@endif</span>
    </button>
@else
    <span {{ $attributes->class(['pos-person']) }} data-plain>
        @if ($avatar)<x-pos.avatar :name="$name" :size="$size" />@endif
        <span class="pos-person-name">{{ $name }}@if ($sub)<span class="pos-person-sub"> · {{ $sub }}</span>@endif</span>
    </span>
@endif
