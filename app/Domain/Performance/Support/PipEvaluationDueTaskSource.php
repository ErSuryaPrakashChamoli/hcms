<?php

namespace App\Domain\Performance\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\ImprovementPlans\ImprovementPlanResource;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity GO-9 (matrix 27 §27.11 #14, HROne "PIP evaluation"): a "My tasks" item for the plan's manager when an
 * active (or extended) improvement plan reaches its end date without an outcome — "evaluate now". PIP confidentiality:
 * only the manager the plan names gets it, and only while they may still see and manage the plan (HR rights, or a
 * performance manager of the employee holding performance.team); never the employee and never anyone else. The title names
 * the employee only, never the reason or the objectives. Due on the plan's end date.
 */
final class PipEvaluationDueTaskSource implements ExperienceTaskSource
{
    public function __construct(private readonly PerformanceRelationships $relationships) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null) {
            return collect();
        }

        return ImprovementPlan::query()->withoutGlobalScope(AccessScope::class)
            ->with(['employee' => fn ($e) => $e->withoutGlobalScope(AccessScope::class)->with('person')])
            ->where('manager_id', $employee->id)->whereIn('status', ['active', 'extended'])
            ->where('end_date', '<=', today()->toDateString().' 23:59:59')
            ->orderBy('end_date')->orderBy('id')->limit(200)->get()
            ->filter(fn (ImprovementPlan $plan) => (int) $plan->employee_id !== (int) $employee->id && $this->manages($user, $plan))
            ->map(fn (ImprovementPlan $plan) => new ExperienceTask('performance', 'pip_evaluation_due',
                'Evaluate the improvement plan of '.($plan->employee?->person?->full_name ?? 'an employee'), 'Plan ended '.$plan->end_date->format('d M Y'),
                $plan->end_date->copy()->endOfDay(), $this->url(), ['employee_id' => (int) $plan->employee_id, 'requested_at' => $plan->end_date]))
            ->values();
    }

    /** As ImprovementPlans: HR who manage plans, or a performance manager of the employee holding performance.team — who may also see it. */
    private function manages(User $user, ImprovementPlan $plan): bool
    {
        $manages = $user->hasPermission('performance.manage') || $user->hasPermission('performance.pip')
            || ($user->hasPermission('performance.team') && $this->relationships->manages($this->relationships->forUser($user), $plan->employee_id));

        return $manages && app(EmployeeOwnedPolicy::class)->view($user, $plan);
    }

    private function url(): ?string
    {
        try {
            return ImprovementPlanResource::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }
}
