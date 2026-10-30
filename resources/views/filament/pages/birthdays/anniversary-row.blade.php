{{-- One person on the Celebrations page's anniversary tab: who, where they work, when; the wish button only on the day. --}}
@php
    $mine = (int) $e->user_id === (int) auth()->id();
    $where = collect([$e->currentPosition?->designation?->name, $e->currentPosition?->department?->name])->filter()->implode(' · ');
@endphp
<div class="pos-stream-row pos-row-actions-below" wire:key="{{ $key }}-{{ $e->id }}">
    <x-pos.person :id="in_array($e->id, $this->openable, true) ? $e->id : null" :name="$e->person->display_name" size="sm" />
    <div class="pos-stream-body">
        <p class="pos-stream-meta">{{ $when }}@if ($where !== '') · {{ $where }}@endif
            @if ($this->manages() && $kind === 'wedding_anniversary' && ! $e->person->wedding_anniversary_shared) · <span title="Not shown to colleagues">private</span>@endif
        </p>
    </div>
    <div class="pos-stream-end">
        @if ($wishable && $mine)
            <x-pos.status tone="success" label="Happy anniversary!" />
        @elseif ($wishable && $this->canWishAnniversary($e))
            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="mountAction('wishAnniversary', @js(['employee' => $e->id, 'kind' => $kind]))" data-pos-action="wish">Send wishes</button>
        @elseif ($wishable)
            <x-pos.status tone="neutral" label="Wished" />
        @endif
    </div>
</div>
