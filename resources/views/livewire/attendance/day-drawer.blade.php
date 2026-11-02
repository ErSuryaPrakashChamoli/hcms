<div x-data="{ open: false, lastFocus: null }"
    x-on:pos-day-drawer-open.window="lastFocus = document.activeElement; open = true"
    x-effect="document.documentElement.classList.toggle('pos-drawer-is-open', open)"
    data-day-drawer>
    {{-- HROne parity TX-7: the HR day drawer (matrix 24 §24.5.2 #3; shared with matrix 20 RQ-3). --}}
    <div x-show="open" x-cloak class="pos-overlay" x-on:click="open = false; $wire.close(); $nextTick(() => lastFocus?.focus?.())" x-transition.opacity.duration.200ms aria-hidden="true"></div>

    <div x-show="open" x-cloak x-trap.noscroll.inert="open" role="dialog" aria-modal="true" aria-labelledby="pos-day-drawer-title"
        class="pos-drawer pos-drawer-wide"
        x-on:keydown.escape.prevent.stop="open = false; $wire.close(); $nextTick(() => lastFocus?.focus?.())"
        x-transition:enter="pos-drawer-in" x-transition:leave="pos-drawer-out">

        @php($d = $this->details)
        <header class="pos-drawer-header">
            <h2 id="pos-day-drawer-title" class="pos-h3 flex-1 truncate">
                @if ($d)
                    {{ $d['employee']->person?->display_name ?? $d['employee']->employee_code }} · {{ $d['date']->format('D j M Y') }}
                @else
                    Day details
                @endif
            </h2>
            @if ($d && ($calendar = $this->calendarUrl()))
                <a href="{{ $calendar }}" class="pos-btn pos-btn-ghost pos-btn-sm" data-day-calendar>Calendar</a>
            @endif
            <button type="button" class="pos-icon-btn" x-on:click="open = false; $wire.close(); $nextTick(() => lastFocus?.focus?.())" aria-label="Close panel"><x-filament::icon icon="heroicon-m-x-mark" class="size-5" /></button>
        </header>

        <div class="pos-drawer-body">
            <div wire:loading.delay.short wire:target="show, __dispatch" class="w-full">
                @include('livewire.experience.skeleton', ['rows' => 4, 'title' => 'the day'])
            </div>
            <div wire:loading.remove wire:target="show, __dispatch" class="grid gap-5">
                @if ($d === null)
                    @if ($employeeId !== null)
                        <x-pos.state variant="denied" title="Not available" why="This employee is not in your view, or the day could not be read." />
                    @endif
                @else
                    @php($canPunch = $this->canChangePunches())
                    @php($canOverride = $this->canOverride())
                    @php($evidence = $this->evidenceVisible())

                    @if ($message)
                        <p class="pos-inline-callout" role="status" data-day-message>{{ $message }}</p>
                    @endif
                    @if ($error)
                        <p class="pos-caption text-pos-danger" role="alert" data-day-error>{{ $error }}</p>
                    @endif

                    {{-- The day as processed --}}
                    <section class="grid gap-2" aria-label="The day">
                        <div class="flex flex-wrap items-center gap-2">
                            @php($tone = in_array($d['status'], ['present', 'wfh', 'on_duty', 'field_duty'], true) ? 'success' : (in_array($d['status'], ['half_day', 'incomplete'], true) ? 'warning' : (in_array($d['status'], ['absent', 'unpaid_leave'], true) ? 'danger' : ($d['status'] === null ? 'neutral' : 'info'))))
                            <x-pos.status :tone="$tone" :label="$d['status_label']" />
                            @if ($d['locked'])<x-pos.status tone="neutral" label="Locked (finalized)" />@endif
                            @if ($d['closed'])<x-pos.status tone="neutral" label="In a closed attendance month" />@endif
                            @if ($d['holiday'])<x-pos.status tone="accent" :label="'Holiday · '.$d['holiday']" />@endif
                            @if ($d['weekly_off'] && ! $d['holiday'])<x-pos.status tone="neutral" label="Weekly off" />@endif
                        </div>
                        <dl class="pos-facts">
                            <div><dt>Shift</dt><dd>{{ $d['shift'] ? $d['shift']->name.' ('.$d['shift']->code.')' : 'No shift' }}</dd></div>
                            @if ($d['record'])
                                <div><dt>In – out</dt><dd class="pos-num">{{ $d['record']->first_in?->setTimezone($d['timezone'])->format('H:i') ?? '–' }} – {{ $d['record']->last_out?->setTimezone($d['timezone'])->format('H:i') ?? '–' }}</dd></div>
                                <div><dt>Worked</dt><dd class="pos-num">{{ sprintf('%d:%02d', intdiv((int) $d['record']->worked_minutes, 60), (int) $d['record']->worked_minutes % 60) }}</dd></div>
                                @if ($d['record']->late_minutes || $d['record']->early_leave_minutes)
                                    <div><dt>Late / early</dt><dd class="pos-num">{{ (int) $d['record']->late_minutes }} m / {{ (int) $d['record']->early_leave_minutes }} m</dd></div>
                                @endif
                                @if (! empty($d['record']->exceptions))
                                    <div><dt>Exceptions</dt><dd>{{ collect($d['record']->exceptions)->map(fn ($e) => config('peopleos.attendance.exception_types.'.$e, $e))->implode(', ') }}</dd></div>
                                @endif
                            @endif
                        </dl>
                        @if ($this->canReprocess())
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="reprocess" wire:loading.attr="disabled" wire:target="reprocess" data-day-reprocess>Reprocess</button>
                                <span class="pos-caption pos-muted">Recalculate the day from its punches, leave, requests and overrides.</span>
                            </div>
                        @endif
                    </section>

                    {{-- Punches --}}
                    <section class="grid gap-2" aria-label="Punches">
                        <div class="flex items-center justify-between gap-2">
                            <p class="pos-section-title">Punches</p>
                            @if ($canPunch && ! $d['date']->isFuture())
                                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="startForm('add')" data-day-add-punch>Add punch</button>
                            @endif
                        </div>
                        @if ($form === 'add')
                            <form class="pos-panel pos-panel-pad grid gap-2" wire:submit="addPunch" data-day-form="add">
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <label class="pos-label">Date
                                        <x-filament::input.wrapper><x-filament::input type="date" wire:model="punchDate" /></x-filament::input.wrapper>
                                    </label>
                                    <label class="pos-label">Time
                                        <x-filament::input.wrapper><x-filament::input type="time" wire:model="punchTime" /></x-filament::input.wrapper>
                                    </label>
                                </div>
                                <label class="pos-label">In / Out
                                    <select wire:model="direction" class="pos-select w-full">
                                        @foreach (\App\Domain\Attendance\Services\PunchCorrections::DIRECTIONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="pos-label">Reason (kept as the punch's note)
                                    <textarea wire:model="reason" rows="2" maxlength="255" class="pos-textarea" required></textarea>
                                </label>
                                <div class="flex items-center gap-2">
                                    <button type="submit" class="pos-btn pos-btn-primary pos-btn-sm" wire:loading.attr="disabled" wire:target="addPunch">Add punch</button>
                                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="cancelForm">Cancel</button>
                                </div>
                            </form>
                        @endif
                        @if ($d['punches'] === [])
                            <p class="pos-body-sm pos-muted">No punches on this day.</p>
                        @else
                            <ul class="pos-list">
                                @foreach ($d['punches'] as $p)
                                    <li class="pos-list-row grid gap-1" data-punch="{{ $p['id'] }}" data-punch-status="{{ $p['status'] }}">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="pos-num font-semibold {{ $p['counted'] ? '' : 'line-through' }}">{{ $p['time']->format($p['other_day'] ? 'D j M H:i:s' : 'H:i:s') }}</span>
                                            <span class="pos-caption">{{ ucfirst($p['direction']) }} · {{ $p['source'] }}@if ($p['device']) · {{ $p['device'] }}@endif</span>
                                            @if ($p['excluded'])
                                                <x-pos.status tone="warning" label="Excluded" />
                                            @elseif ($p['rejected'] !== null)
                                                <x-pos.status tone="danger" label="Refused at check-in" />
                                            @elseif (! $p['in_window'])
                                                <x-pos.status tone="neutral" label="Outside the day's punch window" />
                                            @endif
                                        </div>
                                        <p class="pos-caption pos-muted">
                                            @if ($p['geo_status'])Geo: {{ str_replace('_', ' ', $p['geo_status']) }}@if ($p['geo_location']) · {{ $p['geo_location'] }}@endif @if ($p['distance_m'] !== null)· {{ $p['distance_m'] }} m @endif · @endif
                                            @if ($evidence)
                                                @if ($p['has_location'])Location {{ $p['latitude'] }}, {{ $p['longitude'] }} · @endif
                                                @if ($p['ip_address'])IP {{ $p['ip_address'] }} · @endif
                                                @if ($p['wifi'])Wi-Fi {{ $p['wifi'] }} · @endif
                                            @elseif ($p['has_location'] || $p['photo_count'] > 0)
                                                Check-in evidence kept (location / photos shown only to the geo-access audience) ·
                                            @endif
                                            @if ($p['recorded_by'])Recorded by {{ $p['recorded_by'] }} · @endif
                                            @if ($p['note']){{ $p['note'] }}@endif
                                        </p>
                                        @if ($evidence && $p['photo_count'] > 0)
                                            <p class="pos-caption flex flex-wrap gap-2" data-punch-photos>
                                                @foreach ($this->photoLinks($p['punch']) as $i => $url)
                                                    <a href="{{ $url }}" class="pos-link" target="_blank" rel="noopener">Photo {{ $i + 1 }}</a>
                                                @endforeach
                                                @if ($p['face_verified'] !== null)<span class="pos-muted">Face {{ $p['face_verified'] ? 'verified' : 'not verified' }} by the app</span>@endif
                                            </p>
                                        @endif
                                        @if ($p['excluded'])
                                            <p class="pos-caption">Excluded{{ $p['excluded_by'] ? ' by '.$p['excluded_by'] : '' }}{{ $p['excluded_at'] ? ' on '.$p['excluded_at']->format('j M Y, H:i') : '' }}: {{ $p['exclusion_reason'] }}</p>
                                        @elseif ($p['rejected'] !== null)
                                            <p class="pos-caption">{{ $p['rejected'] }}</p>
                                        @endif

                                        @if ($canPunch && ($p['excluded'] || $p['counted']))
                                            @php($key = ($p['excluded'] ? 'restore:' : 'exclude:').$p['id'])
                                            @if ($form === $key)
                                                <form class="grid gap-2" wire:submit="{{ $p['excluded'] ? 'restorePunch' : 'excludePunch' }}({{ $p['id'] }})" data-day-form="{{ $key }}">
                                                    <label class="pos-label">Reason
                                                        <textarea wire:model="reason" rows="2" maxlength="500" class="pos-textarea" required></textarea>
                                                    </label>
                                                    <div class="flex items-center gap-2">
                                                        <button type="submit" class="pos-btn pos-btn-sm {{ $p['excluded'] ? 'pos-btn-primary' : 'pos-btn-danger' }}">{{ $p['excluded'] ? 'Restore punch' : 'Exclude punch' }}</button>
                                                        <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="cancelForm">Cancel</button>
                                                    </div>
                                                </form>
                                            @else
                                                <div>
                                                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="startForm('{{ $key }}')" data-punch-action="{{ $p['excluded'] ? 'restore' : 'exclude' }}">{{ $p['excluded'] ? 'Restore' : 'Exclude' }}</button>
                                                </div>
                                            @endif
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            <p class="pos-caption pos-muted">Punches are never deleted: an excluded punch is kept as evidence and skipped when the day is calculated.</p>
                        @endif
                    </section>

                    {{-- Leave on the day (through the leave contract) --}}
                    <section class="grid gap-2" aria-label="Leave">
                        <p class="pos-section-title">Leave</p>
                        @forelse ($d['leave'] as $leave)
                            <div class="pos-panel pos-panel-pad grid gap-1" data-leave="{{ $leave['id'] }}">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ $leave['type'] }}@if ($leave['code']) ({{ $leave['code'] }})@endif</span>
                                    <x-pos.status :tone="$leave['status'] === 'approved' ? 'success' : 'warning'" :label="$leave['status_label']" />
                                    <span class="pos-caption pos-muted">{{ \Illuminate\Support\Carbon::parse($leave['from'])->format('j M') }} – {{ \Illuminate\Support\Carbon::parse($leave['to'])->format('j M Y') }} · {{ rtrim(rtrim(number_format($leave['days'], 2), '0'), '.') }} day(s) · this day {{ \App\Domain\Leave\Models\LeaveRequest::SESSIONS[$leave['session']] ?? $leave['session'] }}{{ $leave['paid'] ? '' : ' · unpaid' }}</span>
                                </div>
                                @if ($leave['reason'])<p class="pos-caption pos-muted">{{ $leave['reason'] }}</p>@endif
                                @if ($leave['can_cancel'] && $this->actionable())
                                    @if ($form === 'cancel:'.$leave['id'])
                                        <form class="grid gap-2" wire:submit="cancelLeave({{ $leave['id'] }})" data-day-form="cancel:{{ $leave['id'] }}">
                                            <label class="pos-label">Reason
                                                <textarea wire:model="reason" rows="2" maxlength="255" class="pos-textarea" required></textarea>
                                            </label>
                                            <div class="flex items-center gap-2">
                                                <button type="submit" class="pos-btn pos-btn-danger pos-btn-sm">Cancel this leave</button>
                                                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="cancelForm">Keep it</button>
                                            </div>
                                            <p class="pos-caption pos-muted">The whole request is cancelled the way Leave requests cancels it; the balance and the days are put right.</p>
                                        </form>
                                    @else
                                        <div><button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="startForm('cancel:{{ $leave['id'] }}')" data-leave-cancel="{{ $leave['id'] }}">Cancel leave</button></div>
                                    @endif
                                @endif
                            </div>
                        @empty
                            <p class="pos-body-sm pos-muted">No leave on this day.</p>
                        @endforelse
                    </section>

                    {{-- HR's override of the day (TX-6's form) and regularisations --}}
                    <section class="grid gap-2" aria-label="Overrides">
                        <div class="flex items-center justify-between gap-2">
                            <p class="pos-section-title">Override</p>
                            @if ($canOverride && $form !== 'override')
                                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="startForm('override')" data-day-override>Change the day</button>
                            @endif
                        </div>
                        @if ($d['override'])
                            <div class="pos-body-sm" data-override="{{ $d['override']->id }}">
                                {{ $d['override']->hasHalves() ? $d['override']->halvesLabel() : ($d['override']->status ? \App\Domain\Attendance\Models\AttendanceOverride::halfLabel($d['override']->status) : 'Calculated status') }}@if ($d['override']->worked_minutes !== null) · worked {{ sprintf('%d:%02d', intdiv($d['override']->worked_minutes, 60), $d['override']->worked_minutes % 60) }}@endif
                                <span class="pos-caption pos-muted">· {{ $d['override']->reason }}{{ $d['override']->creator ? ' · '.$d['override']->creator->name : '' }}</span>
                                @if ($canOverride && $form !== 'clear')
                                    <button type="button" class="pos-link ms-2" wire:click="startForm('clear')" data-day-clear>Remove</button>
                                @endif
                            </div>
                            @if ($form === 'clear')
                                <form class="grid gap-2" wire:submit="clearOverride" data-day-form="clear">
                                    <label class="pos-label">Reason
                                        <textarea wire:model="reason" rows="2" maxlength="500" class="pos-textarea" required></textarea>
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <button type="submit" class="pos-btn pos-btn-danger pos-btn-sm">Remove the override</button>
                                        <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="cancelForm">Cancel</button>
                                    </div>
                                </form>
                            @endif
                        @else
                            <p class="pos-body-sm pos-muted">No override: the day is calculated from its punches, leave and requests.</p>
                        @endif
                        @if ($form === 'override')
                            <form class="pos-panel pos-panel-pad grid gap-3" wire:submit="saveOverride" data-day-form="override">
                                {{ $this->overrideForm }}
                                <div class="flex items-center gap-2">
                                    <button type="submit" class="pos-btn pos-btn-primary pos-btn-sm" wire:loading.attr="disabled" wire:target="saveOverride">Save</button>
                                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="cancelForm">Cancel</button>
                                </div>
                                <p class="pos-caption pos-muted">The day is recalculated when saved; approved full-day leave keeps the day.</p>
                            </form>
                        @endif
                        @foreach ($d['regularisations'] as $reg)
                            <p class="pos-caption" data-regularisation="{{ $reg->id }}">Regularisation ({{ $reg->status }}): {{ $reg->requested_in?->setTimezone($d['timezone'])->format('H:i') ?? '–' }} – {{ $reg->requested_out?->setTimezone($d['timezone'])->format('H:i') ?? '–' }} · {{ $reg->reason }}</p>
                        @endforeach
                    </section>
                @endif
            </div>
        </div>
    </div>

    <x-filament-actions::modals />
</div>
