<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\AttendanceCycle;
use App\Domain\Attendance\Contracts\AttendancePeriodState;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceMonthClose;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3 #12, owner decisions TIM-1 / D-TX1 and D-TX6): HR closes a company's
 * attendance month ("Process attendance") and may reopen it ("Unprocess attendance").
 *
 * - Off by default (attendance.month_close.enabled): nothing is closed and every check here answers "open", so days
 *   lock only when they are finalized downstream, as before.
 * - Closed days behave like locked days: requests, overrides, roster changes, uploads and leave on them are refused
 *   unless attendance.allow_corrections_after_lock is on; the calculation keeps their values (with the setting on, a
 *   correction becomes an attendance arrear instead of a change, exactly as for a locked day).
 * - A month closes once its last day has passed, while it is open, not finalized downstream and — with
 *   attendance.close_requires_no_pending (default on) — with no request still waiting for a decision on its days.
 * - Reopening needs a reason and is refused once the month is finalized downstream (its days locked for good). The
 *   arrears its corrections produced while closed are voided and those days recalculated, so nothing counts twice.
 * - Consumers read the state through AttendancePeriodState (they warn about an open month; nothing waits for it).
 */
final class AttendanceMonthCloses implements AttendancePeriodState
{
    public const SETTING = 'attendance.month_close.enabled';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AttendanceCycle $cycle,
        private readonly AttendanceMonthPopulation $population,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING, false);
    }

    public function closingInUse(): bool
    {
        return $this->enabled();
    }

    public function monthState(int $companyId, int $year, int $month): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $row = $this->record($companyId, $year, $month);

        return [
            'status' => $row?->isClosed() ? 'closed' : 'open',
            'label' => Carbon::create($year, $month, 1)->format('M Y'),
            'closed_at' => $row?->isClosed() ? $row->closed_at?->toIso8601String() : null,
            'closed_by' => $row?->isClosed() ? $row->closer()->value('name') : null,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the company's attendance month */
    public function window(int $companyId, int $year, int $month): array
    {
        return $this->cycle->window($companyId, $year, $month);
    }

    public function record(int $companyId, int $year, int $month): ?AttendanceMonthClose
    {
        return AttendanceMonthClose::query()->where('company_id', $companyId)->where('year', $year)->where('month', $month)->first();
    }

    public function isClosed(int $companyId, int $year, int $month): bool
    {
        return $this->enabled() && (bool) $this->record($companyId, $year, $month)?->isClosed();
    }

    /**
     * The days from $from to $to that fall in a closed month of the company the employee belonged to that day.
     *
     * @return list<string> Y-m-d
     */
    public function closedDates(Employee|int $employee, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        if (! $this->enabled()) {
            return [];
        }
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();
        $closes = AttendanceMonthClose::query()->where('status', 'closed')
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())
            ->get(['company_id', 'start_date', 'end_date']);
        if ($closes->isEmpty()) {
            return [];
        }
        $positions = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee instanceof Employee ? $employee->id : $employee)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $to->toDateString().' 23:59:59'))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
            ->orderBy('effective_from')->orderBy('id')->get(['company_id', 'effective_from', 'effective_to']);

        $dates = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $position = $positions->last(fn (EmployeePosition $p) => ($p->effective_from === null || $p->effective_from->lte($day))
                && ($p->effective_to === null || $p->effective_to->gte($day)));
            if ($position !== null && $closes->contains(fn (AttendanceMonthClose $c) => (int) $c->company_id === (int) $position->company_id
                && $c->start_date->lte($day) && $c->end_date->gte($day))) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    public function isClosedDay(Employee|int $employee, CarbonInterface|string $date): bool
    {
        return $this->closedDates($employee, $date, $date) !== [];
    }

    /**
     * The lock rule for closed days: refused unless corrections after lock are allowed (then the calculation keeps the
     * closed day and records the difference as an arrear).
     */
    public function assertOpen(Employee|int $employee, CarbonInterface|string $from, CarbonInterface|string $to, string $message = 'Part of this period is in a closed attendance month.'): void
    {
        if (! $this->enabled() || (bool) $this->settings->get('attendance.allow_corrections_after_lock', false)) {
            return;
        }
        if ($this->closedDates($employee, $from, $to) !== []) {
            throw new RuntimeException($message);
        }
    }

    /**
     * What stands in the way of closing the month (empty when it may close): reasons for people.
     *
     * @return list<string>
     */
    public function closeBlockers(int $companyId, int $year, int $month): array
    {
        if (! $this->enabled()) {
            return ['Closing attendance months is switched off (Setup → Time office).'];
        }
        try {
            [$start, $end] = $this->window($companyId, $year, $month);
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }
        $label = Carbon::create($year, $month, 1)->format('M Y');
        $blockers = [];
        if ($this->record($companyId, $year, $month)?->isClosed()) {
            $blockers[] = "{$label} is already closed.";
        }
        if ($end->gte(today())) {
            $blockers[] = "{$label} runs until {$end->format('j M Y')}: a month closes once its last day has passed.";
        }
        if ($this->cycle->isFinal($companyId, $year, $month)) {
            $blockers[] = "{$label} is already finalized: its days are locked for good.";
        }
        if ((bool) $this->settings->get('attendance.close_requires_no_pending', true)) {
            $ids = $this->population->query($companyId, $start, $end, false)->pluck('id')->all();
            $pending = array_filter(array_map('count', $this->population->pending($ids, $start, $end, false)));
            if ($pending !== []) {
                $blockers[] = 'Requests on days of '.$label.' are still waiting for a decision ('.implode(', ', array_map(
                    fn (string $kind, int $count) => $count.' '.mb_strtolower(AttendanceMonthPopulation::PENDING_KINDS[$kind]), array_keys($pending), $pending)).'). Decide or cancel them first.';
            }
        }

        return $blockers;
    }

    public function close(int $companyId, int $year, int $month, User $actor, ?string $note = null): AttendanceMonthClose
    {
        $this->assertMayRun($actor, $companyId);
        if (($blockers = $this->closeBlockers($companyId, $year, $month)) !== []) {
            throw new RuntimeException(implode(' ', $blockers));
        }
        [$start, $end] = $this->window($companyId, $year, $month);
        $note = filled($note) ? mb_substr(trim((string) $note), 0, 255) : null;

        try {
            return DB::transaction(function () use ($companyId, $year, $month, $start, $end, $actor, $note) {
                $row = AttendanceMonthClose::query()->where('company_id', $companyId)->where('year', $year)->where('month', $month)->lockForUpdate()->first()
                    ?? new AttendanceMonthClose(['company_id' => $companyId, 'year' => $year, 'month' => $month]);
                if ($row->isClosed()) {
                    throw new RuntimeException("{$row->label()} is already closed.");
                }
                $row->fill(['start_date' => $start, 'end_date' => $end, 'status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now(), 'close_note' => $note])
                    ->withAuditReason($note ?? 'Attendance month closed')->save();

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('Someone else closed this month at the same moment; refresh and look again.');
        }
    }

    public function reopen(int $companyId, int $year, int $month, User $actor, string $reason): AttendanceMonthClose
    {
        $this->assertMayRun($actor, $companyId);
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Give the reason for reopening the month.');
        }

        return DB::transaction(function () use ($companyId, $year, $month, $actor, $reason) {
            $row = AttendanceMonthClose::query()->where('company_id', $companyId)->where('year', $year)->where('month', $month)->lockForUpdate()->first();
            if ($row === null || ! $row->isClosed()) {
                throw new RuntimeException('This month is not closed.');
            }
            $employeeIds = $this->population->query($companyId, $row->start_date, $row->end_date, false)->pluck('id')->all();
            $locked = AttendanceRecord::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $employeeIds)->where('is_locked', true)
                ->whereDate('date', '>=', $row->start_date->toDateString())->whereDate('date', '<=', $row->end_date->toDateString())->exists();
            if ($locked || $this->cycle->isFinal($companyId, $year, $month)) {
                throw new RuntimeException("{$row->label()} is finalized: its days are locked for good, so it cannot be reopened. Later corrections follow the corrections-after-lock setting.");
            }

            $row->fill(['status' => 'open', 'reopened_by' => $actor->id, 'reopened_at' => now(), 'reopen_reason' => mb_substr($reason, 0, 255)])->withAuditReason($reason)->save();
            $this->unfreeze($row, $employeeIds, $reason);

            return $row;
        });
    }

    /**
     * Arrears recorded for corrections while the month was closed (pending or held, on days not locked) are voided and
     * their days recalculated now that they are open: the correction lands on the day itself.
     *
     * @param  list<int>  $employeeIds
     */
    private function unfreeze(AttendanceMonthClose $row, array $employeeIds, string $reason): void
    {
        $arrears = AttendanceArrear::query()->withoutGlobalScope(AccessScope::class)->with('employee')->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['pending', 'held'])->whereDate('date', '>=', $row->start_date->toDateString())->whereDate('date', '<=', $row->end_date->toDateString())
            ->orderBy('date')->get();
        $processor = app(AttendanceProcessor::class);
        foreach ($arrears as $arrear) {
            $lockedDay = AttendanceRecord::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $arrear->employee_id)
                ->whereDate('date', $arrear->date->toDateString())->where('is_locked', true)->exists();
            if ($lockedDay || $arrear->employee === null || $this->isClosedDay($arrear->employee, $arrear->date)) {
                continue;
            }
            $arrear->withAuditReason("Month {$row->label()} reopened ({$reason}): the day is recalculated instead")->update(['status' => 'void']);
            $processor->process($arrear->employee, $arrear->date->copy());
        }
    }

    private function assertMayRun(User $actor, int $companyId): void
    {
        if (! $actor->hasPermission('attendance.process')) {
            throw new RuntimeException('You cannot close or reopen attendance months.');
        }
        if (Company::query()->whereKey($companyId)->doesntExist()) {
            throw new RuntimeException('This company is not in your access.');
        }
    }
}
