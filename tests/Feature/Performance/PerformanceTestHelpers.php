<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\RatingScale;
use Illuminate\Support\Carbon;

/** A draft annual cycle on the default five-point scale, all default stages, rating three competencies. */
function draftCycle(array $overrides = []): PerformanceCycle
{
    return PerformanceCycle::create(array_merge([
        'name' => 'FY26 annual', 'code' => 'FY26', 'type' => 'annual', 'period_start' => '2026-04-01', 'period_end' => '2027-03-31',
        'rating_scale_id' => RatingScale::default()->id,
        'stages' => PerformanceCycle::defaultStages(Carbon::parse('2027-03-31')),
        'weights' => ['goals' => 70, 'competencies' => 30],
        'competency_ids' => Competency::query()->orderBy('id')->limit(3)->pluck('id')->all(),
    ], $overrides));
}

/** An active employee with a login, reporting to $manager. */
function activeEmployee(?Employee $manager = null, array $permissions = ['performance.goals', 'performance.review', 'performance.feedback', 'task.view']): Employee
{
    $employee = employeeWithUser($manager, $permissions);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);

    return $employee->refresh();
}
