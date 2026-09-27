<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\ImprovementPlan;
use Illuminate\Support\Carbon;
use RuntimeException;

/** PIPs (§34): open, extend, close with an outcome. */
final class ImprovementPlans
{
    public function __construct(private readonly AuditRecorder $audit, private readonly Timeline $timeline) {}

    /** @param  array<int, array{objective: string, measure?: string, due?: string}>  $objectives */
    public function open(Employee $employee, Employee|int|null $manager, string $reason, array $objectives, Carbon|string $start, Carbon|string $end, ?int $appraisalId = null, ?User $actor = null): ImprovementPlan
    {
        if ($objectives === []) {
            throw new RuntimeException('A plan needs at least one objective.');
        }
        if (ImprovementPlan::query()->where('employee_id', $employee->id)->whereIn('status', ['active', 'extended'])->exists()) {
            throw new RuntimeException('An improvement plan is already active for this employee.');
        }

        $plan = ImprovementPlan::create([
            'employee_id' => $employee->id,
            'manager_id' => $manager instanceof Employee ? $manager->id : $manager,
            'appraisal_id' => $appraisalId,
            'start_date' => Carbon::parse($start),
            'end_date' => Carbon::parse($end),
            'reason' => $reason,
            'objectives' => array_values($objectives),
            'status' => 'active',
            'created_by' => $actor?->id ?? auth()->id(),
        ]);

        $this->audit->record(AuditAction::Create, 'performance', $plan, [], $reason, actor: $actor);
        $this->timeline->record($employee, 'performance', 'Improvement plan opened', $plan->start_date, null, $plan);
        PerformanceEvent::dispatch('performance.pip.opened', $employee, $plan, ['until' => $plan->end_date->toDateString()], array_filter([$employee->id, $plan->manager_id]));

        return $plan;
    }

    public function extend(ImprovementPlan $plan, Carbon|string $newEnd, string $reason): ImprovementPlan
    {
        if (! $plan->isOpen()) {
            throw new RuntimeException('The plan is closed.');
        }
        $plan->withAuditReason($reason)->update(['end_date' => Carbon::parse($newEnd), 'status' => 'extended']);

        return $plan;
    }

    public function close(ImprovementPlan $plan, string $status, string $outcome, ?User $actor = null): ImprovementPlan
    {
        if (! in_array($status, ['completed', 'unsuccessful', 'withdrawn'], true)) {
            throw new RuntimeException('Close as completed, unsuccessful or withdrawn.');
        }
        if (! $plan->isOpen()) {
            throw new RuntimeException('The plan is already closed.');
        }

        $plan->update(['status' => $status, 'outcome' => $outcome, 'closed_at' => now()]);
        $this->audit->record(AuditAction::Update, 'performance', $plan, [['field' => 'status', 'before' => 'active', 'after' => $status]], $outcome, actor: $actor);
        $this->timeline->record($plan->employee, 'performance', 'Improvement plan '.config("peopleos.performance.pip_statuses.{$status}"), now(), null, $plan);

        return $plan;
    }
}
