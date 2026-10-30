@php
    /**
     * @var \App\Domain\Experience\Support\InboxEntry $entry
     * @var array|null $peek  @var list<array> $more  @var bool $ignorable  @var bool $restorable  @var array|null $trail
     */
    $due = $entry->dueLabel();
    $key = md5($entry->source.$entry->id);
@endphp
{{-- HROne parity RQ-8: the open inbox item — who sent it, to whom, by when, and what can be done with it here --}}
<div class="space-y-2" data-inbox-entry="{{ $entry->id }}" wire:key="detail-{{ $key }}">
    <section class="pos-panel pos-panel-pad" aria-label="About this item">
        <div class="flex flex-wrap items-center gap-2">
            <x-pos.status tone="neutral" :label="$entry->facetLabel" />
            @if ($due)<x-pos.status :tone="$due['tone']" :label="$due['label']" />@endif
            @if ($entry->dueAt)<span class="pos-meta ms-auto">Due <time datetime="{{ $entry->dueAt->toIso8601String() }}">{{ $entry->dueAt->format('D j M, H:i') }}</time></span>@endif
        </div>
        <p class="pos-body-sm mt-2" data-inbox-route>
            <span class="pos-muted">From</span> {{ $entry->from ?? '—' }}
            <span class="pos-muted">· To</span> {{ $entry->item?->isDelegated() ? 'You, on behalf of '.$entry->item->onBehalfOfName : 'You' }}
            @if ($entry->requestedAt)<span class="pos-muted">· Raised</span> <time datetime="{{ $entry->requestedAt->toIso8601String() }}">{{ $entry->requestedAt->format('d M Y, H:i') }}</time>@endif
        </p>
        @if ($entry->overallStatus)
            {{-- R.5 #2: a configuration form's run — where it stands overall --}}
            <p class="pos-body-sm mt-1" data-overall-status><span class="pos-muted">Overall status</span> {{ $entry->overallStatus }}</p>
        @endif

        @if ($more !== [] || $ignorable)
            <div class="mt-3 flex flex-wrap items-center gap-2" x-data="{ more: false, ignoring: false, reason: '' }">
                @if ($more !== [])
                    {{-- §20.2.1 #22–25: the existing forms, opened with this person picked where the form allows it --}}
                    <div class="relative">
                        <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-on:click="more = ! more" :aria-expanded="more.toString()" aria-expanded="false" aria-controls="pos-inbox-more-{{ $key }}" data-inbox-more>More</button>
                        <ul id="pos-inbox-more-{{ $key }}" class="pos-panel pos-stream mt-2" x-show="more" x-cloak x-on:click.outside="more = false" role="list">
                            @foreach ($more as $link)
                                <li><a href="{{ $link['url'] }}" class="pos-stream-row" data-more="{{ $link['key'] }}"><span class="pos-stream-body"><span class="pos-stream-title">{{ $link['label'] }}</span></span></a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($ignorable)
                    {{-- §20.2.1 #27: a system task closed as not relevant, with a reason (task.ignore; audited; restorable) --}}
                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-show="! ignoring" x-on:click="ignoring = true; $nextTick(() => $refs.reason?.focus())" data-inbox-ignore>Not relevant</button>
                    <form class="w-full" x-show="ignoring" x-cloak x-on:submit.prevent="$wire.ignore(@js($entry->id), reason)" x-on:keydown.escape.stop="ignoring = false">
                        <label class="pos-label" for="ignore-{{ $key }}">Why is this task not relevant? (required)</label>
                        <textarea x-ref="reason" id="ignore-{{ $key }}" x-model="reason" rows="2" maxlength="255" class="pos-textarea" required></textarea>
                        @error('ignore.'.$entry->id)<p class="pos-caption text-pos-danger" role="alert">{{ $message }}</p>@enderror
                        <div class="mt-2 flex items-center gap-2">
                            <button type="submit" class="pos-btn pos-btn-primary pos-btn-sm" wire:loading.attr="disabled" wire:target="ignore">Confirm: not relevant</button>
                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-on:click="ignoring = false">Cancel</button>
                        </div>
                    </form>
                @endif
            </div>
        @endif
    </section>

    @if ($entry->isDecision())
        <x-pos.approval-card :item="$entry->item" :archivable="true" :trailable="true" :trail="$trail" class="pos-approval-detail" />
    @elseif ($entry->source === 'archived')
        <article class="pos-card" data-inbox-archived>
            <h3 class="pos-section-title">{{ $entry->title }}</h3>
            <p class="pos-meta mt-2">{{ $entry->subject ? $entry->subject.' · ' : '' }}Archived: it stays pending, and its reminders still run.</p>
            <div class="mt-4 flex items-center gap-2">
                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" x-on:click="$wire.restoreArchived(@js($entry->item->id), @js($entry->item->type))" data-restore-archived>Restore</button>
                @if ($entry->url)<a href="{{ $entry->url }}" wire:navigate class="pos-link ms-auto">View details</a>@endif
            </div>
        </article>
    @elseif ($entry->source === 'not_relevant')
        <article class="pos-card" data-inbox-not-relevant x-data="{ note: '' }">
            <h3 class="pos-section-title">{{ $entry->title }}</h3>
            <p class="pos-meta mt-2">{{ $entry->subject ? $entry->subject.' · ' : '' }}Marked not relevant{{ $entry->from ? ' by '.$entry->from : '' }}{{ ($entry->meta['closed_at'] ?? null) ? ' · '.$entry->meta['closed_at']->format('d M Y') : '' }}</p>
            @if ($entry->note)<blockquote class="pos-quote mt-3">{{ $entry->note }}</blockquote>@endif
            @if ($restorable)
                <label class="pos-label mt-4 block" for="restore-{{ $key }}">Note (optional)</label>
                <textarea id="restore-{{ $key }}" x-model="note" rows="1" maxlength="255" class="pos-textarea"></textarea>
                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm mt-2" x-on:click="$wire.restoreTask({{ (int) $entry->taskId }}, note)" data-restore-task>Bring it back</button>
            @endif
        </article>
    @else
        {{-- A task of the person's own, or a missed workflow step: opened on its own screen --}}
        <article class="pos-card" data-inbox-task>
            <h3 class="pos-section-title">{{ $entry->title }}</h3>
            @if ($entry->subject)<p class="pos-meta mt-2">{{ $entry->subject }}</p>@endif
            @if ($entry->source === 'missed')
                <p class="pos-body-sm mt-3">You can no longer respond: the other approvers of this step decided it.</p>
            @endif
            @if ($entry->url)
                <div class="mt-4"><a href="{{ $entry->url }}" class="pos-btn pos-btn-secondary pos-btn-sm">{{ $entry->source === 'missed' ? 'Open run' : 'Open' }}</a></div>
            @endif
        </article>
    @endif

    @if ($peek !== null)
        {{-- §20.2.1 #21: the employee it is about, as the directory shows them to the viewer (CA-6 sections, access scopes) --}}
        <section class="pos-panel pos-panel-pad" aria-label="Profile" data-inbox-peek>
            <div class="flex items-center gap-3">
                <x-pos.avatar :name="$peek['name']" size="sm" />
                <div class="min-w-0">
                    <p class="pos-body-sm font-medium">{{ $peek['name'] }}</p>
                    <p class="pos-meta">{{ collect([$peek['title'], $peek['team'], $peek['location']])->filter()->implode(' · ') }}</p>
                </div>
                @if ($peek['profile'])<a href="{{ $peek['profile'] }}" class="pos-link ms-auto" data-peek-profile>View profile</a>@endif
            </div>
            @if ($peek['manager'] || $peek['status'])
                <p class="pos-meta mt-2">{{ collect([$peek['manager'] ? 'Manager: '.$peek['manager'] : null, $peek['status']])->filter()->implode(' · ') }}</p>
            @endif
            @foreach ($peek['sections'] ?? [] as $section)
                <div class="mt-3" data-directory-section="{{ $section['key'] }}">
                    <p class="pos-caption pos-muted">{{ $section['label'] }}</p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($section['items'] as $line)
                            <li class="pos-body-sm"><span class="font-medium">{{ $line['title'] }}</span>@if ($line['detail']) <span class="pos-muted">· {{ $line['detail'] }}</span>@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>
    @endif
</div>
