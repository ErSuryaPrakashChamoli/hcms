{{-- Decisions waiting for you --}}
@if ($h['decision_count'] > 0 || in_array($h['experience'], ['manager', 'hr'], true))
    <x-pos.section title="Decisions waiting for you" :count="$h['decision_count'] ? $h['decision_count'].(($h['decision_more'] ?? false) ? '+' : '') : null" :link="\App\Filament\Pages\Approvals::canAccess() ? \App\Filament\Pages\Approvals::getUrl() : null" link-label="Approval Center">
        @if ($h['decisions']->isEmpty())
            <x-pos.state variant="caught-up" size="inline" title="No decisions are waiting for you." why="Leave, attendance corrections, pay changes, letters and workflow steps that need you will appear here first." />
        @else
            <div class="pos-panel pos-stream">
                @foreach ($h['decisions'] as $item)
                    @php($group = $item->group())
                    <div class="pos-stream-row" data-tone="{{ $group === 'urgent' ? 'danger' : ($group === 'today' ? 'warning' : 'info') }}" wire:key="dec-{{ md5($item->id) }}" wire:transition>
                        <span class="pos-stream-mark" aria-hidden="true"></span>
                        <div class="pos-stream-body">
                            <p class="pos-stream-title">{{ $item->title }}</p>
                            <p class="pos-stream-meta flex flex-wrap items-center gap-x-2">
                                @if ($item->subject)<x-pos.person :id="$item->subjectEmployeeId" :name="$item->subject" />@endif
                                <span>{{ $item->typeLabel }}</span>
                                @if ($item->effectiveOn)<span>· from {{ $item->effectiveOn->format('D j M') }}</span>@elseif ($item->dueAt)<span>· due {{ $item->dueAt->diffForHumans() }}</span>@endif
                                @if ($group === 'urgent')<x-pos.status tone="danger" :label="$item->riskReason ?? 'Urgent'" />@endif
                            </p>
                        </div>
                        <div class="pos-stream-end">
                            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'approval', id: @js($item->id) })">Review</button>
                        </div>
                    </div>
                @endforeach
                @if ($h['decision_count'] > $h['decisions']->count())
                    <div class="pos-stream-more"><a href="{{ \App\Filament\Pages\Approvals::getUrl() }}" wire:navigate class="pos-link">{{ $h['decision_count'] - $h['decisions']->count() }}{{ ($h['decision_more'] ?? false) ? '+' : '' }} more in the Approval Center</a></div>
                @endif
            </div>
        @endif
    </x-pos.section>
@endif
