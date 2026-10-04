@props(['item', 'compact' => false])
@php
    /** @var \App\Domain\Experience\Support\ApprovalItem $item */
    $group = $item->group();
    $requester = $item->requestedBy;
@endphp
{{--
    PeopleApproval: one decision with its context. Person, request, reason, impact, effective date and the
    Before → After are beside the decision; deciding confirms in place with a note (a reason is required to
    reject or send back). Decisions go through ApprovalDecisions to the owning domain service.
--}}
<article {{ $attributes->class(['pos-approval-card', 'pos-card']) }} data-approval-id="{{ $item->id }}" data-group="{{ $group }}" tabindex="-1"
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
        @if ($item->effectiveOn && ($compact || $item->changes === []))
            <span class="pos-change-when ms-auto"><x-filament::icon icon="heroicon-m-calendar" class="size-3.5" />Effective {{ $item->effectiveOn->format('D, j M') }}</span>
        @elseif ($item->dueAt)
            <span class="pos-meta ms-auto">Due {{ $item->dueAt->diffForHumans() }}</span>
        @endif
    </header>

    <h3 id="pos-appr-{{ md5($item->id) }}" class="{{ $compact ? 'pos-h3' : 'pos-section-title' }} mt-3">{{ $item->title }}</h3>

    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
        @if ($item->subject)
            <x-pos.person :id="$item->subjectEmployeeId" :name="$item->subject" size="sm" />
        @endif
        @if ($requester || $item->requestedAt)
            <span class="pos-meta">Requested{{ $requester ? ' by '.$requester : '' }}{{ $item->requestedAt ? ' · '.$item->requestedAt->diffForHumans() : '' }}</span>
        @endif
    </div>

    @if ($item->reason)
        <blockquote class="pos-quote mt-3">{{ $item->reason }}</blockquote>
    @endif

    @if (! $compact)
        @if ($item->impact || $item->facts !== [])
            <ul class="pos-decision-context mt-4" aria-label="Context">
                @if ($item->impact)
                    <li><x-filament::icon icon="heroicon-m-light-bulb" class="size-4" /><span>{{ $item->impact }}</span></li>
                @endif
                @foreach ($item->facts as $fact)
                    <li><x-filament::icon icon="heroicon-m-information-circle" class="size-4" /><span>{{ $fact }}</span></li>
                @endforeach
            </ul>
        @endif
        <x-pos.change :changes="$item->changes" :effective="$item->effectiveOn" class="mt-4" />
    @endif

    <footer class="mt-5">
        <div class="flex flex-wrap items-center gap-2" x-show="mode === null">
            @foreach (['approve' => 'pos-btn-success', 'complete' => 'pos-btn-success', 'reject' => 'pos-btn-secondary', 'request_change' => 'pos-btn-ghost'] as $decision => $style)
                @if ($item->can($decision))
                    <button type="button" class="pos-btn {{ $style }} pos-btn-sm" x-on:click="mode = @js($decision); $nextTick(() => $refs.note?.focus())" data-decision="{{ $decision }}">{{ $item->label($decision) }}</button>
                @endif
            @endforeach
            @if ($item->url)
                <a href="{{ $item->url }}" wire:navigate class="pos-link ms-auto">View details</a>
            @endif
        </div>
        @if (! $compact && ! $item->can('request_change') && ($item->can('approve') || $item->can('reject')))
            <p class="pos-meta mt-3" x-show="mode === null">Need more information? This kind of request can’t be sent back in PeopleOS. Reject with a reason the requester will see, or ask {{ $requester ?? 'them' }} directly first.</p>
        @endif
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
