{{-- My attendance: one day of one's own month (AttendanceCalendar::dayPanel). "Regularise" and "Apply leave" are the
     panel's footer buttons; a note says why one is not offered. --}}
<div class="pos-cal pos-cal-sheet">
    @if ($day === null)
        <p class="pos-cal-empty">This day is not available.</p>
    @else
        @php($date = $day['date']->toDateString())
        <div @class(['pos-cal-sheet-status', 'is-planned' => $day['planned']]) data-tone="{{ $day['tone'] }}">
            <span class="pos-cal-code">{{ $day['code'] ?: '·' }}</span>
            <div class="min-w-0">
                <strong>{{ $day['label'] }}</strong>
                @if ($day['holiday'])
                    <span>{{ $day['holiday'] }}</span>
                @elseif ($day['shift'])
                    <span>Shift {{ $day['shift'] }} · {{ $day['shift_title'] }}</span>
                @elseif ($day['no_shift'])
                    <span class="is-unassigned">No shift assigned</span>
                @endif
            </div>
        </div>

        @if ($day['in'] || $day['out'] || $day['late'] > 0 || $day['early'] > 0)
            <dl class="pos-cal-facts">
                <div><dt>In</dt><dd>{{ $day['in'] ?? '–' }}</dd></div>
                <div><dt>Out</dt><dd>{{ $day['out'] ?? '–' }}</dd></div>
                @if ($day['late'] > 0)
                    <div><dt>Late by</dt><dd>{{ $day['late'] }} min</dd></div>
                @endif
                @if ($day['early'] > 0)
                    <div><dt>Left early by</dt><dd>{{ $day['early'] }} min</dd></div>
                @endif
            </dl>
        @endif

        @if ($day['issue'])
            <p class="pos-cal-callout" data-tone="incomplete">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="pos-cal-callout-icon" />
                @if ($day['own'])
                    @if ($day['tone'] === 'not_processed')
                        No attendance is recorded for this day yet. If you worked and a punch is missing, regularise it; if you were away, apply for leave.
                    @else
                        This day needs attention. Regularise it if your attendance was not captured correctly, or apply for leave.
                    @endif
                @elseif ($day['tone'] === 'not_processed')
                    No attendance is recorded for {{ $day['name'] }} on this day yet: a missing punch can be regularised, an absence covered by leave.
                @else
                    This day needs attention: regularise it if {{ $day['name'] }}'s attendance was not captured correctly, or apply leave for it.
                @endif
            </p>
        @endif

        <section class="pos-cal-sheet-part" aria-labelledby="pos-day-leave">
            <h4 id="pos-day-leave" class="pos-cal-sheet-title">Leave on this day</h4>
            @forelse ($day['leave'] as $leave)
                <div class="pos-cal-sheet-row">
                    <span class="min-w-0">
                        <strong>{{ $leave->leaveType?->name ?? 'Leave' }}</strong>
                        <span>{{ \App\Domain\Leave\Models\LeaveRequest::SESSIONS[$leave->sessionOn($date) ?? 'full'] ?? 'Full day' }} · {{ $leave->from_date->format('j M') }}@if (! $leave->from_date->eq($leave->to_date)) – {{ $leave->to_date->format('j M') }}@endif · {{ rtrim(rtrim(number_format((float) $leave->days, 2), '0'), '.') }} {{ (float) $leave->days == 1.0 ? 'day' : 'days' }}</span>
                    </span>
                    <span class="pos-cal-req" data-state="{{ in_array($leave->status, \App\Domain\Leave\Models\LeaveRequest::TAKEN, true) ? 'approved' : $leave->status }}">{{ $leave->status === 'cancel_requested' ? 'Cancellation asked' : (\App\Domain\Leave\Models\LeaveRequest::STATUSES[$leave->status] ?? ucfirst($leave->status)) }}</span>
                </div>
            @empty
                <p class="pos-cal-empty">None.</p>
            @endforelse
        </section>

        <section class="pos-cal-sheet-part" aria-labelledby="pos-day-ar">
            <h4 id="pos-day-ar" class="pos-cal-sheet-title">Regularisations for this day</h4>
            @forelse ($day['regularisations'] as $ar)
                <div class="pos-cal-sheet-row">
                    <span class="min-w-0">
                        <strong>{{ config("peopleos.attendance.regularisation_types.{$ar->type}", $ar->type) }}</strong>
                        <span>
                            @if ($ar->requested_in || $ar->requested_out)
                                In {{ $ar->requested_in?->setTimezone(config('app.timezone'))->format('H:i') ?? '–' }}, out {{ $ar->requested_out?->setTimezone(config('app.timezone'))->format('H:i') ?? '–' }} ·
                            @endif
                            {{ \Illuminate\Support\Str::limit((string) $ar->reason, 60) }}
                            @if ($ar->review_note)
                                · Note: {{ \Illuminate\Support\Str::limit($ar->review_note, 60) }}
                            @endif
                        </span>
                    </span>
                    <span class="pos-cal-req" data-state="{{ $ar->status }}">{{ $ar->status === 'cancelled' ? 'Withdrawn' : (\App\Domain\Attendance\Models\AttendanceRegularisation::STATUSES[$ar->status] ?? ucfirst($ar->status)) }}</span>
                </div>
            @empty
                <p class="pos-cal-empty">None.</p>
            @endforelse
        </section>

        @foreach ($day['notes'] as $note)
            <p class="pos-cal-note-line">{{ $note }}</p>
        @endforeach

        @if ($day['apply_leave'])
            <p class="pos-cal-note-line">{{ $day['own'] ? 'You have' : $day['name'].' has' }} {{ rtrim(rtrim(number_format($day['available'], 2), '0'), '.') }} {{ $day['available'] == 1 ? 'day' : 'days' }} of paid leave left.</p>
        @endif
    @endif
</div>
