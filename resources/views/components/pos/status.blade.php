@props(['tone' => 'neutral', 'label' => null])
{{-- Status never relies on colour alone: the dot is paired with a text label. --}}
<span {{ $attributes->class(['pos-status', 'pos-status-'.$tone]) }}><span class="pos-dot" aria-hidden="true"></span>{{ $label ?? $slot }}</span>
