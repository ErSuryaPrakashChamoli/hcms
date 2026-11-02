<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\AttendanceCycle;
use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3 #8, #10: "Process attendance arrear" / "Unprocess attendance arrear"): HR chooses
 * which earlier months' corrections are taken now. Arrears stay automatic (recorded by the calculation); holding one
 * leaves it out until it is released, and only a pending or held arrear can move — a settled one never changes. Needs
 * attendance.arrears and the employee in the person's access; every move is audited with its reason.
 *
 * Data import DI-35 ("Override arrear days", matrix 24 §24.9 #4; decision D35-1): HR may also set the arrear days of an
 * employee's earlier attendance month by hand (setForMonth) — the month's arrear total, to give back (> 0) or take back (< 0).
 * As in the leading suites, an override takes the month over: the calculation's pending or held arrears of the month are
 * voided ("replaced"), the calculation records no new ones for its days, and what is left to take is the figure less what
 * runs already settled for the month (so uploading the same figure twice never takes it twice). HR's figure is one arrear
 * with source `upload` covering the month (`covers_from` … `date`, the month's last day); a new figure replaces it while it
 * is pending or held (a held one stays held).
 */
final class AttendanceArrears
{
    public const PERMISSION = 'attendance.arrears';

    public function __construct(
        private readonly AccessScopes $scopes,
        private readonly AttendanceCycle $cycle,
        private readonly AttendanceMonthPopulation $population,
    ) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the first and last day of a company's attendance month */
    public function window(int $companyId, int $year, int $month): array
    {
        return $this->cycle->window($companyId, $year, $month);
    }

    /**
     * What setting the month's arrear days to $days does: HR's pending or held figure on file, what runs already settled for
     * the month, what is left to take (`delta`), the calculation's pending or held arrears it replaces, and whether anything
     * changes at all.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, current: ?AttendanceArrear, settled: float, delta: float, replaced: Collection<int, AttendanceArrear>, changes: bool}
     */
    public function planForMonth(int $employeeId, int $companyId, int $year, int $month, float $days, bool $locking = false): array
    {
        [$start, $end] = $this->window($companyId, $year, $month);
        $inMonth = fn () => AttendanceArrear::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString());
        $settled = round((float) $inMonth()->where('status', 'settled')->when($locking, fn ($q) => $q->sharedLock())->sum('delta_days'), 2);
        $current = $inMonth()->where('source', 'upload')->whereIn('status', ['pending', 'held'])->latest('id')->when($locking, fn ($q) => $q->lockForUpdate())->first();
        $replaced = $inMonth()->where('source', 'calculation')->whereIn('status', ['pending', 'held'])->orderBy('date')->when($locking, fn ($q) => $q->lockForUpdate())->get();
        $delta = round($days - $settled, 2);
        $changes = $replaced->isNotEmpty() || ($current !== null ? abs((float) $current->delta_days - $delta) >= 0.001 : abs($delta) >= 0.001);

        return ['start' => $start, 'end' => $end, 'current' => $current, 'settled' => $settled, 'delta' => $delta, 'replaced' => $replaced, 'changes' => $changes];
    }

    /** Why the actor may not set these arrear days for the employee's month (null = may). */
    public function setRefusal(Employee $employee, int $companyId, int $year, int $month, float $days, ?User $actor): ?string
    {
        if ($actor !== null) {
            if (! $actor->hasPermission(self::PERMISSION)) {
                return 'You cannot work on attendance arrears.';
            }
            if ($employee->user_id !== null && (int) $employee->user_id === (int) $actor->id) {
                return 'You cannot set your own arrear days.';
            }
            if (! $this->scopes->allows($actor, $employee)) {
                return 'This employee is not in your access.';
            }
        }
        $label = Carbon::create($year, $month, 1)->format('M Y');
        try {
            [$start, $end] = $this->window($companyId, $year, $month);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        [$currentYear, $currentMonth] = $this->cycle->monthOf($companyId, CarbonImmutable::today());
        if ($year * 12 + $month >= $currentYear * 12 + $currentMonth) {
            return "Arrear days are for an earlier month; {$label} is not over yet.";
        }
        $span = (int) $start->diffInDays($end) + 1;
        if (abs($days) > $span) {
            return "Arrear days must be a number of days from -{$span} to {$span}.";
        }
        if (! $this->population->query($companyId, $start, $end, false)->whereKey($employee->getKey())->exists()) {
            return "The employee does not belong to this company in {$label}.";
        }

        return null;
    }

    /**
     * Set the arrear days of the employee's earlier month (decision D35-1, above). Audited with the reason; HR's arrear waits
     * for the next run like any other. Returns HR's arrear for the month (void when nothing is left to take).
     */
    public function setForMonth(Employee $employee, int $companyId, int $year, int $month, float $days, string $reason, User $actor): AttendanceArrear
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Give the reason.');
        }
        $days = round($days, 2);
        if (($refusal = $this->setRefusal($employee, $companyId, $year, $month, $days, $actor)) !== null) {
            throw new RuntimeException($refusal);
        }
        $label = Carbon::create($year, $month, 1)->format('M Y');

        $arrear = DB::transaction(function () use ($employee, $companyId, $year, $month, $days, $reason, $label) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->value('id');
            $plan = $this->planForMonth((int) $employee->getKey(), $companyId, $year, $month, $days, locking: true);
            foreach ($plan['replaced'] as $calculated) {
                $calculated->withAuditReason(mb_substr("Replaced by the arrear days HR set for {$label}: {$reason}", 0, 255))->update(['status' => 'void']);
            }
            $arrear = $plan['current'] ?? new AttendanceArrear(['employee_id' => $employee->getKey(), 'date' => $plan['end']->toDateString(), 'source' => 'upload', 'status' => 'pending']);
            $nothingLeft = abs($plan['delta']) < 0.001;
            $arrear->fill([
                'covers_from' => $plan['start']->toDateString(), 'status_before' => 'as_paid', 'status_after' => 'set_by_hr', 'paid_before' => $plan['settled'],
                'paid_after' => $days, 'delta_days' => $plan['delta'], 'note' => mb_substr("Set by HR: {$days} day(s) for {$label}: {$reason}", 0, 255),
                'status' => $nothingLeft ? 'void' : ($plan['current']?->status ?? 'pending'),
            ])->withAuditReason(mb_substr($reason, 0, 255))->save();

            return $arrear;
        });
        AttendanceEvent::dispatch('attendance.arrear_recorded', $employee, $arrear, ['date' => $arrear->date->toDateString(), 'from' => 'as_paid', 'to' => 'set_by_hr',
            'delta_days' => (float) $arrear->delta_days, 'source' => 'upload']);

        return $arrear;
    }

    public function hold(AttendanceArrear $arrear, User $actor, string $reason): AttendanceArrear
    {
        return $this->move($arrear, $actor, $reason, 'pending', 'held');
    }

    public function release(AttendanceArrear $arrear, User $actor, string $reason): AttendanceArrear
    {
        return $this->move($arrear, $actor, $reason, 'held', 'pending');
    }

    /**
     * Hold or release every arrear of the employees whose corrected day falls in a window (HROne: choose the months).
     *
     * @param  list<int>  $employeeIds
     * @return int how many moved
     */
    public function moveWindow(array $employeeIds, CarbonInterface $from, CarbonInterface $to, bool $hold, User $actor, string $reason): int
    {
        $rows = AttendanceArrear::query()->whereIn('employee_id', $employeeIds)->where('status', $hold ? 'pending' : 'held')
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->orderBy('date')->get();
        $moved = 0;
        foreach ($rows as $arrear) {
            $hold ? $this->hold($arrear, $actor, $reason) : $this->release($arrear, $actor, $reason);
            $moved++;
        }

        return $moved;
    }

    private function move(AttendanceArrear $arrear, User $actor, string $reason, string $from, string $to): AttendanceArrear
    {
        if (! $actor->hasPermission('attendance.arrears')) {
            throw new RuntimeException('You cannot work on attendance arrears.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Give the reason.');
        }
        if (! $this->scopes->allows($actor, $arrear)) {
            throw new RuntimeException('This employee is not in your access.');
        }

        return DB::transaction(function () use ($arrear, $reason, $from, $to) {
            $locked = AttendanceArrear::query()->whereKey($arrear->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                throw new RuntimeException(match ($locked->status) {
                    'settled' => 'This arrear is already settled; it no longer changes.',
                    'void' => 'This arrear is void.',
                    default => $to === 'held' ? 'Only a pending arrear can be held.' : 'Only a held arrear can be released.',
                });
            }
            $locked->withAuditReason(mb_substr($reason, 0, 255))->update(['status' => $to]);

            return $locked;
        });
    }
}
