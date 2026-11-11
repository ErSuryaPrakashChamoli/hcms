<x-filament-panels::page>
    {{-- HROne parity TX-3: Run attendance — a company's attendance month in HROne's steps. Figures load after the page.
         Built from the step kit (theme.css .pos-step-*): the month as a calendar card, what to review as tiles, the three
         numbered steps as cards of figures, and one employee's month as three panels. --}}
    <div class="pos-step-pickers is-row">
        <label class="pos-step-picker">
            <span>Company</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="company">
                    @foreach ($this->companies() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label class="pos-step-picker">
            <span>Month</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="month">
                    @foreach ($this->monthOptions() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>

    <div wire:init="load" @if ($this->hasRunningRun()) wire:poll.5s @endif class="pos-step">
        @php $desk = $this->overview(); @endphp
        @if ($desk === null)
            @if ($this->companyId() === null)
                <x-pos.step-wait icon="heroicon-o-building-office-2" title="Add a company first" text="Attendance months run per company." />
            @else
                <div class="pos-step-loading" role="status" wire:loading.remove><x-filament::loading-indicator class="pos-step-loading-icon" /> Loading the month…</div>
            @endif
        @elseif (isset($desk['error']))
            <x-pos.step-banner tone="blocked" title="The month could not be read" :sub="$desk['error']" />
        @else
            {{-- The month and, with month close in use, its state. --}}
            @php
                $close = $desk['close'];
                [$pillTone, $pillLabel] = ! $close['enabled'] ? ['na', 'No month close'] : ($close['status'] === 'closed' ? ['clear', 'Closed'] : ['warning', 'Open']);
            @endphp
            <div class="pos-step-hero">
                <div class="pos-step-cal" aria-hidden="true"><span>{{ $desk['start']->format('M') }}</span><strong>{{ $desk['start']->format('Y') }}</strong></div>
                <div class="pos-step-hero-text">
                    <p class="pos-step-hero-title">{{ $desk['label'] }}</p>
                    <p class="pos-step-hero-sub">{{ $desk['start']->format('j M') }} – {{ $desk['end']->format('j M Y') }} ·
                        {{ $desk['ended'] ? 'The month has ended.' : ($desk['started'] ? 'The month is running.' : 'The month has not started yet.') }}</p>
                </div>
                <span class="pos-step-pill" data-tone="{{ $pillTone }}" data-month-state="{{ $close['enabled'] ? ($close['status'] === 'closed' ? 'closed' : 'open') : 'none' }}">{{ $pillLabel }}</span>
                @if ($close['enabled'] && $this->canProcess())
                    <div class="pos-step-hero-actions">
                        @if ($close['status'] === 'closed')
                            @unless ($close['final'])@if ($this->reopenMonthAction->isVisible()) {{ $this->reopenMonthAction }} @endif @endunless
                        @else
                            @if ($this->closeMonthAction->isVisible()) {{ $this->closeMonthAction }} @endif
                        @endif
                    </div>
                @endif
            </div>

            @if ($close['enabled'])
                @if ($close['status'] === 'closed')
                    <x-pos.step-banner tone="clear" icon="heroicon-o-lock-closed"
                        :title="'Closed'.($close['closed_by'] ? ' by '.$close['closed_by'] : '').($close['closed_at'] ? ' on '.$close['closed_at']->format('j M Y, H:i') : '')"
                        :sub="$close['note'] ?: 'Its days refuse requests and corrections, and payroll reads a closed month.'">
                        @if ($close['window_changed'])<p class="pos-step-note">The pay cycle has changed since the month was closed: the closed days are the dates it had then.</p>@endif
                        @if ($close['final'])<p class="pos-step-note">A finalized payroll covers this month: it can no longer be reopened.</p>@endif
                    </x-pos.step-banner>
                @else
                    <x-pos.step-banner :tone="$close['blockers'] === [] ? 'idle' : 'warning'" icon="heroicon-o-lock-open" title="Open"
                        sub="Close the month when it is ready: its days then refuse requests and corrections, and payroll reads a closed month.">
                        @if ($close['reopened_at'])<p class="pos-step-note">Reopened{{ $close['reopened_by'] ? ' by '.$close['reopened_by'] : '' }} on {{ $close['reopened_at']->format('j M Y') }}: {{ $close['reopen_reason'] }}</p>@endif
                        @if ($close['blockers'] !== [])
                            <ul class="pos-step-reasons">@foreach ($close['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
                        @endif
                        @if ($close['final'])<p class="pos-step-note">A finalized payroll covers this month: it can no longer be reopened.</p>@endif
                    </x-pos.step-banner>
                @endif
            @else
                <p class="pos-step-note"><x-filament::icon icon="heroicon-m-information-circle" class="pos-step-note-icon" />Attendance months are not closed in this organisation: days lock when payroll is finalized.</p>
            @endif

            {{-- "Please review following errors" and "Reminders": a tile each; open one for its list and CSV. --}}
            <x-filament::section heading="Before you close the month" description="Open a tile for its list and CSV.">
                @php
                    $tiles = [
                        'Errors to review' => collect(\App\Domain\Attendance\Services\AttendanceMonthDesk::READINESS)->mapWithKeys(fn ($key) => [$key => $desk['readiness'][$key]])->all(),
                        'Reminders: requests still waiting' => collect(\App\Domain\Attendance\Services\AttendanceMonthPopulation::PENDING_KINDS)->mapWithKeys(fn ($label, $kind) => ['pending_'.$kind => $desk['pending'][$kind] ?? 0])->all(),
                    ];
                @endphp
                <div class="pos-step-review">
                    @foreach ($tiles as $group => $counts)
                        <section class="pos-step-group" aria-label="{{ $group }}">
                            <h3 class="pos-step-subhead">{{ $group }} <span>{{ array_sum($counts) }}</span></h3>
                            <div class="pos-step-tiles">
                                @foreach ($counts as $key => $count)
                                    @php [$tileLabel, $tileHelp] = \App\Domain\Attendance\Services\AttendanceMonthDesk::LISTS[$key]; @endphp
                                    <button type="button" wire:click="toggleList('{{ $key }}')" class="pos-step-tile" data-tone="{{ $count > 0 ? 'warning' : 'clear' }}"
                                        aria-pressed="{{ $openList === $key ? 'true' : 'false' }}" data-list="{{ $key }}" title="{{ $tileHelp }}">
                                        <span class="pos-step-tile-n">{{ $count }}</span>
                                        <span class="pos-step-tile-label">{{ $tileLabel }}</span>
                                        <x-filament::icon :icon="$count > 0 ? 'heroicon-m-chevron-right' : 'heroicon-m-check'" class="pos-step-tile-icon" />
                                    </button>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
                @if ($openList !== null && $openList !== 'unprocessed')
                    @include('filament.pages.partials.run-attendance-list')
                @endif
            </x-filament::section>

            {{-- Step 1 --}}
            @if ($this->canProcess())
                <x-filament::section heading="1 · Attendance pre-verification (optional)" description="Review absent and half days: mark days present or apply leave below.">
                    <div class="pos-step">
                        <dl class="pos-step-stats">
                            <div class="pos-step-stat" data-tone="{{ $desk['absences']['absent_days'] > 0 ? 'warning' : 'clear' }}"><dt>Absent days</dt><dd>{{ $desk['absences']['absent_days'] }}</dd></div>
                            <div class="pos-step-stat" data-tone="{{ $desk['absences']['half_days'] > 0 ? 'warning' : 'clear' }}"><dt>Half days</dt><dd>{{ $desk['absences']['half_days'] }}</dd></div>
                            <div class="pos-step-stat" data-tone="idle"><dt>Employees</dt><dd>{{ $desk['absences']['people'] }}</dd></div>
                        </dl>
                        @if ($desk['absences']['absent_days'] + $desk['absences']['half_days'] > 0)
                            <div class="pos-step-actions">
                                <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadList('absences')">Download</x-filament::button>
                            </div>
                        @endif
                    </div>
                </x-filament::section>
            @endif
        @endif
    </div>

    @if ($this->canProcess() && $this->companyId() !== null)
        {{ $this->table }}
    @endif

    @if ($desk !== null && ! isset($desk['error']))
        {{-- Step 2 --}}
        @if ($this->canWorkArrears())
            <x-filament::section heading="2 · Attendance correction arrears" description="Corrections to days of earlier months that this month takes. Hold a month to leave its arrears for later; release it to take them again. Settled arrears never change.">
                @php
                    $pendingRows = collect($desk['arrears'])->sum('pending');
                    $heldRows = collect($desk['arrears'])->sum('held');
                @endphp
                <div class="pos-step">
                    <dl class="pos-step-stats">
                        <div class="pos-step-stat" data-tone="{{ $pendingRows > 0 ? 'warning' : 'clear' }}"><dt>Ready to take</dt><dd>{{ $pendingRows }}</dd></div>
                        <div class="pos-step-stat" data-tone="{{ $heldRows > 0 ? 'pending' : 'clear' }}"><dt>Held</dt><dd>{{ $heldRows }}</dd></div>
                        <div class="pos-step-stat" data-tone="idle"><dt>Employees</dt><dd>{{ collect($desk['arrears'])->sum('people') }}</dd></div>
                    </dl>
                    @if ($desk['arrears'] === [])
                        <p class="pos-step-note is-good"><x-filament::icon icon="heroicon-m-check-circle" class="pos-step-note-icon" />No arrears waiting for this month.</p>
                    @else
                        <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Arrears by month corrected">
                            <table class="pos-step-table">
                                <thead><tr><th>Arrear for month</th><th class="is-num">Pending (days)</th><th class="is-num">Held (days)</th><th class="is-num">Employees</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($desk['arrears'] as $group)
                                        <tr data-arrear-month="{{ $group['key'] }}">
                                            <td>{{ $group['label'] }}</td>
                                            <td class="is-num">{{ $group['pending'] }} ({{ sprintf('%+.2f', $group['pending_days']) }})</td>
                                            <td class="is-num">{{ $group['held'] }} ({{ sprintf('%+.2f', $group['held_days']) }})</td>
                                            <td class="is-num">{{ $group['people'] }}</td>
                                            <td class="is-end">
                                                @if ($group['pending'] > 0){{ ($this->holdArrearsAction)(['month' => $group['key']]) }}@endif
                                                @if ($group['held'] > 0){{ ($this->releaseArrearsAction)(['month' => $group['key']]) }}@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <div class="pos-step-actions">
                        <a href="{{ $this->arrearsUrl('pending') }}" class="pos-step-link">Review pending arrears<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>
                        <a href="{{ $this->arrearsUrl('held') }}" class="pos-step-link">Review held arrears<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- Step 3 --}}
        @if ($this->canProcess())
            @php
                $p = $desk['processing'];
                $pct = $p['total'] > 0 ? (int) round($p['complete'] * 100 / $p['total']) : 100;
            @endphp
            <x-filament::section heading="3 · Process attendance" description="Calculate the month so far for everyone or the people you choose, in the background. Days locked by payroll or in a closed month keep their values.">
                <div class="pos-step">
                    <dl class="pos-step-stats">
                        <div class="pos-step-stat" data-tone="{{ $p['incomplete'] > 0 ? 'warning' : 'clear' }}">
                            <dt>Fully processed</dt><dd>{{ $p['complete'] }} <small class="pos-step-stat-of">of {{ $p['total'] }}</small></dd>
                            <span class="pos-step-meter" style="--pct: {{ $pct }}" aria-hidden="true"><span></span></span>
                        </div>
                        <div class="pos-step-stat" data-tone="{{ $p['incomplete'] > 0 ? 'warning' : 'clear' }}">
                            <dt>Not yet</dt>
                            <dd><button type="button" class="pos-step-stat-btn" wire:click="toggleList('unprocessed')" aria-pressed="{{ $openList === 'unprocessed' ? 'true' : 'false' }}">{{ $p['incomplete'] }}</button></dd>
                            <span class="pos-step-delta">{{ $p['missing_days'] }} day(s) missing</span>
                        </div>
                        <div class="pos-step-stat" data-tone="idle"><dt>Employees in the month</dt><dd>{{ $p['total'] }}</dd></div>
                    </dl>
                    @if ($close['status'] !== 'closed' && $desk['started'])
                        <div class="pos-step-actions">@if ($this->processMonthAction->isVisible()) {{ $this->processMonthAction }} @endif</div>
                    @endif
                    @if ($openList === 'unprocessed')
                        @include('filament.pages.partials.run-attendance-list')
                    @endif
                    @if ($p['runs']->isNotEmpty())
                        <ol class="pos-step-tasks">
                            @foreach ($p['runs'] as $run)
                                <li class="pos-step-task" data-run="{{ $run->id }}" @if ($run->status !== 'running') data-done @endif>
                                    <span class="pos-step-task-n" aria-hidden="true">
                                        @if ($run->status === 'running')<x-filament::loading-indicator class="pos-step-task-check" />@else<x-filament::icon icon="heroicon-m-check" class="pos-step-task-check" />@endif
                                    </span>
                                    <span class="pos-step-task-text">
                                        <strong>{{ $run->status === 'running' ? 'Processing…' : 'Processed' }} · {{ $run->done }} of {{ $run->total }}{{ $run->failed > 0 ? ' · '.$run->failed.' failed' : '' }}</strong>
                                        <span>Started {{ $run->created_at?->format('j M, H:i') }}{{ $run->starter ? ' by '.$run->starter->name : '' }}{{ $run->finished_at ? ' · finished '.$run->finished_at->format('j M, H:i') : '' }}@if ($run->filters) · {{ implode(' · ', $run->filters) }}@endif</span>
                                        <span class="pos-step-meter" style="--pct: {{ $run->progress() }}; --tone: var(--pos-primary)" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $run->progress() }}" aria-label="Progress"><span></span></span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </x-filament::section>

            {{-- Review one employee --}}
            <x-filament::section heading="Review an employee's month" description="Totals, requests still waiting and arrears for one employee; process just their month.">
                <div class="pos-step">
                    <label class="pos-step-picker is-narrow">
                        <span>Employee</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="reviewEmployee">
                                <option value="">Choose…</option>
                                @foreach ($this->employeeOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    @php $review = $this->review(); @endphp
                    @if ($review)
                        @php $n = fn ($v) => \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $v); @endphp
                        <div class="pos-step-panels">
                            <section class="pos-step-panel">
                                <h3 class="pos-step-subhead">Attendance</h3>
                                @if ($review['summary'])
                                    <p>{{ $review['summary']['days'] }} day(s) processed · {{ $review['summary']['unprocessed_days'] }} not yet</p>
                                    <p>Paid days {{ $review['summary']['paid_days'] }} · absent {{ $review['summary']['absent_days'] }} · half days {{ $review['summary']['half_days'] }}</p>
                                    <p>Penalty days {{ $review['summary']['penalty_days'] }}</p>
                                @else
                                    <p class="is-muted">The month has not started.</p>
                                @endif
                                @if ($review['closed_days'] > 0)<p class="is-muted">{{ $review['closed_days'] }} day(s) in a closed month.</p>@endif
                            </section>
                            <section class="pos-step-panel">
                                <h3 class="pos-step-subhead">Waiting for a decision</h3>
                                @forelse ($review['pending'] as $item)
                                    <p>{{ $item['what'] }} · {{ $item['dates'] }} · {{ $item['status'] }}</p>
                                @empty
                                    <p class="is-muted">Nothing waiting.</p>
                                @endforelse
                            </section>
                            <section class="pos-step-panel">
                                <h3 class="pos-step-subhead">Arrears</h3>
                                @forelse ($review['arrears'] as $arrear)
                                    <p>{{ $arrear->date->format('j M Y') }} · {{ sprintf('%+.2f', (float) $arrear->delta_days) }} · {{ \App\Domain\Attendance\Models\AttendanceArrear::STATUSES[$arrear->status] ?? $arrear->status }}</p>
                                @empty
                                    <p class="is-muted">None pending or held.</p>
                                @endforelse
                            </section>
                        </div>
                        @if ($review['month'])
                            {{-- HROne parity TX-4: the review's "Summary" — hours by kind of day, arrear days and the paid days. --}}
                            @php $m = $review['month']; @endphp
                            <div class="pos-step-panels" data-review-summary>
                                <section class="pos-step-panel">
                                    <h3 class="pos-step-subhead">Summary</h3>
                                    <p>Present {{ $n($m['present']) }} · weekly offs {{ $n($m['weekly_off']) }} · holidays {{ $n($m['holiday']) }} · paid leave {{ $n($m['paid_leave']) }}</p>
                                    <p>Paid days as processed {{ $n($m['paid_days_processed']) }}@if ($m['adjusted']) · adjusted {{ ($m['adjustment'] > 0 ? '+' : '').$n($m['adjustment']) }}@endif · effective paid days <strong>{{ $n($m['paid_days']) }}</strong></p>
                                </section>
                                <section class="pos-step-panel">
                                    <h3 class="pos-step-subhead">Hours</h3>
                                    <p>Working days {{ $m['working_hours'] }} h · weekly offs {{ $m['weekly_off_hours'] }} h · holidays {{ $m['holiday_hours'] }} h</p>
                                    <p>Overtime (approved) {{ $m['overtime_hours'] }} h</p>
                                </section>
                                <section class="pos-step-panel">
                                    <h3 class="pos-step-subhead">Arrear days</h3>
                                    <p>{{ $n($m['arrear_days']) }} (corrections to this month's locked days) · penalty days {{ $n($m['penalty_days']) }}</p>
                                </section>
                            </div>
                        @endif
                        <div class="pos-step-actions">
                            @if ($close['status'] !== 'closed' && $desk['started'])
                                <x-filament::button size="sm" icon="heroicon-o-arrow-path" wire:click="processEmployee">Process this employee's month</x-filament::button>
                            @endif
                            <a href="{{ $review['calendar'] }}" class="pos-step-link">Open the calendar<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>
                            @if (\App\Filament\Pages\PaidDays::canAccess())<a href="{{ $review['paid_days'] }}" class="pos-step-link">Paid days breakdown<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>@endif
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
