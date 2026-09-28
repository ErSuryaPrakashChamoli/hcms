<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\PerformanceCheckIn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Phase 7 continuous check-ins: one per employee, cadence and period; goal progress flows into goals. */
final class PerformanceCheckIns
{
    public const EMPLOYEE_FIELDS = ['went_well', 'blockers', 'support_needed', 'priorities', 'employee_feedback'];

    public function __construct(
        private readonly Goals $goals,
        private readonly AuditRecorder $audit,
        private readonly PerformanceRelationships $relationships,
    ) {}

    /** The period a date falls in: week start (weekly, biweekly), month start (monthly), the date itself (custom). */
    public function periodFor(string $cadence, Carbon|string $date): Carbon
    {
        $date = Carbon::parse($date)->startOfDay();

        return match ($cadence) {
            'weekly' => $date->startOfWeek(),
            // Fortnights anchored on Monday 1 January 2024 so periods never shift across years.
            'biweekly' => $date->startOfWeek()->subWeeks(((int) Carbon::parse('2024-01-01')->diffInWeeks($date->startOfWeek())) % 2),
            'monthly' => $date->startOfMonth(),
            'custom' => $date,
            default => throw new RuntimeException("Unknown check-in cadence '{$cadence}'."),
        };
    }

    /**
     * Save (and optionally submit) the employee's side. $data holds the employee fields, `actions`
     * and `goal_progress` (list of {goal_id, value, note?}).
     */
    public function save(Employee $employee, string $cadence, Carbon|string $date, array $data, bool $submit = false, ?User $actor = null, ?int $cycleId = null): PerformanceCheckIn
    {
        $actor ??= auth()->user();
        if ($actor !== null && ! $actor->hasPermission('performance.manage') && $this->relationships->forUser($actor)?->id !== $employee->id) {
            throw new RuntimeException('Employees write their own check-ins.');
        }
        $period = $this->periodFor($cadence, $date);
        $progress = $this->validatedProgress($employee, $data['goal_progress'] ?? []);

        return DB::transaction(function () use ($employee, $cadence, $period, $data, $submit, $actor, $cycleId, $progress) {
            $checkIn = PerformanceCheckIn::query()->where('employee_id', $employee->id)->where('cadence', $cadence)->whereDate('period_date', $period)->lockForUpdate()->first()
                ?? new PerformanceCheckIn(['employee_id' => $employee->id, 'cadence' => $cadence, 'period_date' => $period->toDateString(), 'status' => 'draft']);
            if ($checkIn->exists && $checkIn->status !== 'draft') {
                throw new RuntimeException('This check-in was already submitted.');
            }

            $checkIn->fill(collect($data)->only(self::EMPLOYEE_FIELDS)->all() + [
                'manager_id' => $employee->currentManager()->value('manager_id'),
                'performance_cycle_id' => $cycleId,
                'goal_progress' => $progress,
                'actions' => array_values($data['actions'] ?? $checkIn->actions ?? []),
            ]);
            if ($submit) {
                $checkIn->fill(['status' => 'submitted', 'submitted_at' => now()]);
            }
            $checkIn->save();

            if ($submit) {
                foreach ($progress as $row) {
                    $this->goals->checkIn(Goal::query()->findOrFail($row['goal_id']), (float) $row['value'], $row['note'] ?? null, null, $actor, 'check_in', "Check-in {$checkIn->period_date->toDateString()}");
                }
                PerformanceEvent::dispatch('performance.check_in.submitted', $employee, $checkIn, ['period' => $checkIn->period_date->toDateString(), 'cadence' => $cadence], array_filter([$checkIn->manager_id]));
            }

            return $checkIn->refresh();
        });
    }

    /** The manager's response: feedback and agreed actions. Only someone who manages the employee. */
    public function respond(PerformanceCheckIn $checkIn, ?string $feedback, array $actions = [], ?User $actor = null): PerformanceCheckIn
    {
        $actor ??= auth()->user();
        $manages = $this->relationships->manages($this->relationships->forUser($actor), $checkIn->employee_id);
        if (! $manages && ! $actor->hasPermission('performance.manage')) {
            throw new RuntimeException('Only a manager of this employee responds to the check-in.');
        }

        return DB::transaction(function () use ($checkIn, $feedback, $actions, $actor) {
            $current = PerformanceCheckIn::query()->whereKey($checkIn->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'submitted') {
                throw new RuntimeException($current->status === 'reviewed' ? 'This check-in was already reviewed.' : 'The employee has not submitted this check-in yet.');
            }
            $checkIn->update([
                'manager_feedback' => $feedback,
                'actions' => array_values([...($current->actions ?? []), ...$actions]),
                'status' => 'reviewed', 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            ]);
            $this->audit->record(AuditAction::Approved, 'performance', $checkIn, [], null, actor: $actor, metadata: ['check_in' => 'reviewed']);
            PerformanceEvent::dispatch('performance.check_in.reviewed', $checkIn->employee, $checkIn, ['period' => $checkIn->period_date->toDateString()], [$checkIn->employee_id]);

            return $checkIn->refresh();
        });
    }

    /** Goal progress may reference only the employee's own open goals. */
    private function validatedProgress(Employee $employee, array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            $goal = Goal::query()->find($row['goal_id'] ?? null);
            if ($goal === null || (int) $goal->employee_id !== (int) $employee->id || ! $goal->isOpen()) {
                throw new RuntimeException('Check-in progress can only update your own open goals.');
            }
            if (! is_numeric($row['value'] ?? null)) {
                throw new RuntimeException('Each goal progress entry needs a numeric value.');
            }
            $clean[] = ['goal_id' => $goal->id, 'value' => (float) $row['value'], 'note' => $row['note'] ?? null];
        }

        return $clean;
    }
}
