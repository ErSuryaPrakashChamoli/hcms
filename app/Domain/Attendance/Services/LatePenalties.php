<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\AttendancePenalty;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Late-mark penalties (HROne parity HP-1). The attendance policy already carries `late_marks_threshold` and
 * `late_mark_action`; this reads them. In each calendar month every Nth late mark puts the action on that
 * day: a warning, half a day or a full day of loss of pay (a quantity the attendance output reports).
 * Re-evaluated whenever a day of the month is processed, so it follows regularisations and reprocessing;
 * waived penalties and days locked by payroll are left as they are.
 */
final class LatePenalties
{
    private const DAYS = ['warn' => 0.0, 'half_day' => 0.5, 'lop' => 1.0];

    public function __construct(private readonly PolicyResolver $policies, private readonly AuditRecorder $audit) {}

    /** @return int penalties now applied in the month */
    public function evaluate(Employee $employee, Carbon|string $day): int
    {
        $day = Carbon::parse($day);
        $monthStart = $day->copy()->startOfMonth();
        $monthEnd = $day->copy()->endOfMonth();
        $month = $monthStart->format('Y-m');

        $policy = $this->policies->resolve('attendance', $employee, $monthEnd->copy()->min(now()));
        $threshold = (int) ($policy?->setting('late_marks_threshold') ?? 0);
        $action = (string) ($policy?->setting('late_mark_action') ?? 'warn');

        $records = AttendanceRecord::query()->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $monthStart->toDateString())->whereDate('date', '<=', $monthEnd->toDateString())
            ->where('late_minutes', '>', 0)->whereIn('status', ['present', 'half_day'])
            ->orderBy('date')->get(['id', 'date', 'is_locked']);
        $locked = AttendanceRecord::query()->where('employee_id', $employee->id)->where('is_locked', true)
            ->whereDate('date', '>=', $monthStart->toDateString())->whereDate('date', '<=', $monthEnd->toDateString())
            ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

        $wanted = [];
        if ($threshold > 0 && array_key_exists($action, self::DAYS)) {
            foreach ($records->chunk($threshold) as $i => $group) {
                if ($group->count() < $threshold) {
                    break;
                }
                $last = $group->last();
                $wanted[$last->date->toDateString()] = ['late_count' => ($i + 1) * $threshold, 'record_ids' => $group->pluck('id')->values()->all()];
            }
        }

        $existing = AttendancePenalty::query()->where('employee_id', $employee->id)->where('month', $month)->where('rule', 'late_marks')->get()
            ->keyBy(fn (AttendancePenalty $p) => $p->date->toDateString());

        foreach ($existing as $date => $penalty) {
            if (! isset($wanted[$date]) && $penalty->status === 'applied' && ! in_array($date, $locked, true)) {
                $penalty->withAuditReason('Late marks recounted')->delete();
            }
        }

        $applied = 0;
        foreach ($wanted as $date => $basis) {
            $penalty = $existing->get($date);
            if ($penalty?->status === 'waived' || in_array($date, $locked, true)) {
                $applied += (int) ($penalty?->status === 'applied');

                continue;
            }
            $values = ['month' => $month, 'action' => $action, 'days' => self::DAYS[$action], 'late_count' => $basis['late_count'], 'record_ids' => $basis['record_ids'], 'status' => 'applied'];
            $isNew = $penalty === null;
            $penalty ??= new AttendancePenalty(['employee_id' => $employee->id, 'date' => $date, 'rule' => 'late_marks']);
            $penalty->fill($values);
            if ($penalty->isDirty()) {
                $penalty->withAuditReason("{$basis['late_count']} late marks in {$month}")->save();
                if ($isNew) {
                    AttendanceEvent::dispatch('attendance.penalty_applied', $employee, $penalty, ['date' => $date, 'action' => $action, 'days' => $values['days'], 'late_count' => $basis['late_count']]);
                }
            }
            $applied++;
        }

        return $applied;
    }

    public function waive(AttendancePenalty $penalty, string $reason, ?User $actor = null): AttendancePenalty
    {
        if ($penalty->status !== 'applied') {
            throw new RuntimeException('This penalty is not applied.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for waiving.');
        }
        $penalty->loadMissing('employee');
        if (AttendanceRecord::query()->where('employee_id', $penalty->employee_id)->whereDate('date', $penalty->date->toDateString())->where('is_locked', true)->exists()) {
            throw new RuntimeException('The day is locked for payroll.');
        }
        $actor ??= auth()->user();

        $penalty->withAuditReason($reason)->update(['status' => 'waived', 'waived_by' => $actor?->id, 'waive_reason' => $reason]);
        $this->audit->record(AuditAction::StatusChange, 'attendance', $penalty, reason: $reason, actor: $actor, metadata: ['waived' => true]);
        AttendanceEvent::dispatch('attendance.penalty_waived', $penalty->employee, $penalty, ['date' => $penalty->date->toDateString(), 'reason' => $reason]);

        return $penalty;
    }
}
