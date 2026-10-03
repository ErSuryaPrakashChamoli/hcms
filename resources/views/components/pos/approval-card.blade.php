@props(['item', 'compact' => false])
@php
    /** @var \App\Domain\Experience\Support\ApprovalItem $item */
    $group = $item->group();
@endphp
<article {{ $attributes->class(['pos-approval', 'pos-card']) }} data-approval-id="{{ $item->id }}" data-group="{{ $group }}" tabindex="-1"
    x-data="{ mode: null, note: '' }"
    x-on:pos-approval-shortcut.window="if ($event.detail.id === @js($item->id)) { mode = $event.detail.decision; $nextTick(() => $refs.note?.focus()) }"
    aria-labelledby="pos-appr-{{ md5($item->id) }}">
    <header class="flex flex-wrap items-center gap-2">
        <x-pos.status tone="neutral" :label="$item->typeLabel" />
        @if ($item->risk === 'high')
            <x-pos.status tone="danger" :label="$item->riskReason ?? 'Needs care'" />
        @elseif ($group === 'urgent')
            <x-pos.status tone="warning" label="Urgent" />
        @endif
        @if ($item->effectiveOn)
            <span class="pos-caption pos-muted ms-auto">Effective {{ $item->effectiveOn->format('D, d M') }}</span>
        @elseif ($item->dueAt)
            <span class="pos-caption pos-muted ms-auto">Due {{ $item->dueAt->diffForHumans() }}</span>
        @endif
    </header>

    <h3 id="pos-appr-{{ md5($item->id) }}" class="pos-h3 mt-2">{{ $item->title }}</h3>

    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
        @if ($item->subject)
            @if ($item->subjectEmployeeId)
                <button type="button" class="pos-person-inline" x-on:click="$dispatch('pos-drawer-open', { type: 'person', id: {{ $item->subjectEmployeeId }} })">
                    <x-pos.avatar :name="$item->subject" size="xs" /><span>{{ $item->subject }}</span>
                </button>
            @else
                <span class="pos-body-sm">{{ $item->subject }}</span>
            @endif
        @endif
        @if ($item->requestedBy || $item->requestedAt)
            <span class="pos-caption pos-muted">Requested{{ $item->requestedBy ? ' by '.$item->requestedBy : '' }}{{ $item->requestedAt ? ' · '.$item->requestedAt->diffForHumans() : '' }}</span>
        @endif
    </div>

    @if ($item->reason)
        <blockquote class="pos-quote mt-3">{{ $item->reason }}</blockquote>
    @endif

    @if (! $compact)
        @if ($item->impact)
            <p class="pos-body-sm mt-3 flex items-start gap-2"><x-filament::icon icon="heroicon-m-light-bulb" class="mt-0.5 size-4 shrink-0 text-pos-primary" /><span>{{ $item->impact }}</span></p>
        @endif
        @foreach ($item->facts as $fact)
            <p class="pos-caption pos-muted mt-1">{{ $fact }}</p>
        @endforeach
        <x-pos.before-after :changes="$item->changes" class="mt-3" />
    @endif

    <footer class="mt-4">
        <div class="flex flex-wrap items-center gap-2" x-show="mode === null">
            @foreach (['approve' => 'pos-btn-success', 'complete' => 'pos-btn-success', 'reject' => 'pos-btn-secondary', 'request_change' => 'pos-btn-ghost'] as $decision => $style)
                @if ($item->can($decision))
                    <button type="button" class="pos-btn {{ $style }} pos-btn-sm" x-on:click="mode = @js($decision); $nextTick(() => $refs.note?.focus())" data-decision="{{ $decision }}">{{ $item->label($decision) }}</button>
                @endif
            @endforeach
            @if ($item->url)
                <a href="{{ $item->url }}" wire:navigate class="pos-link ms-auto">Open record</a>
            @endif
        </div>
        <form class="pos-decide" x-show="mode !== null" x-cloak x-on:submit.prevent="$wire.decide(@js($item->id), mode, note)" x-on:keydown.escape.stop="mode = null">
            <label class="pos-label" :for="'note-{{ md5($item->id) }}'">
                <span x-text="mode === 'approve' || mode === 'complete' ? 'Note (optional)' : 'Reason (required, the requester sees it)'"></span>
            </label>
            <textarea x-ref="note" id="note-{{ md5($item->id) }}" x-model="note" rows="2" maxlength="2000" class="pos-textarea"
                :required="mode === 'reject' || mode === 'request_change'"></textarea>
            @error('note.'.$item->id)<p class="pos-caption text-pos-danger" role="alert">{{ $message }}</p>@enderror
            <div class="mt-2 flex items-center gap-2">
                <button type="submit" class="pos-btn pos-btn-sm" :class="mode === 'reject' ? 'pos-btn-danger' : 'pos-btn-primary'" wire:loading.attr="disabled" wire:target="decide">
                    <span x-text="{ approve: @js('Confirm: '.$item->label('approve')), complete: 'Confirm: done', reject: @js('Confirm: '.$item->label('reject')), request_change: @js('Send: '.$item->label('request_change')) }[mode]"></span>
                </button>
                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-on:click="mode = null">Cancel</button>
                <span class="pos-caption pos-muted ms-auto hidden sm:inline">Esc to cancel</span>
            </div>
        </form>
    </footer>
</article>
