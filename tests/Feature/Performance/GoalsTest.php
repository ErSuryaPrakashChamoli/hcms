<?php

use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Services\Goals;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->goals = app(Goals::class);
    $this->employee = activeEmployee();
});

it('rolls progress up from key results by weight and cascades to parent goals', function () {
    $company = $this->goals->create(['level' => 'company', 'title' => 'Grow ARR 40%']);
    $team = $this->goals->create(['level' => 'team', 'title' => 'Ship v2', 'parent_id' => $company->id]);
    $mine = $this->goals->create(['employee_id' => $this->employee->id, 'parent_id' => $team->id, 'type' => 'okr', 'title' => 'Platform reliability', 'weight' => 60], [
        ['title' => 'p95 latency 500 → 200ms', 'measure_type' => 'numeric', 'start_value' => 500, 'target_value' => 200, 'current_value' => 500, 'weight' => 50],
        ['title' => 'Coverage to 80%', 'target_value' => 80, 'current_value' => 40, 'weight' => 50],
    ]);

    expect((float) $mine->progress)->toBe(25.0) // 0% + 50% averaged
        ->and($this->employee->user->notifications()->count())->toBe(1);

    $this->goals->checkIn($mine->keyResults->first(), 260, 'Cache layer live', 'on_track');
    $mine->refresh();
    expect((float) $mine->progress)->toBe(65.0)
        ->and((float) $mine->keyResults()->first()->progress)->toBe(80.0)
        ->and($mine->checkIns()->count())->toBe(1)
        ->and((float) $team->refresh()->progress)->toBe(65.0)
        ->and((float) $company->refresh()->progress)->toBe(65.0);

    $this->goals->checkIn($mine, 100); // a goal with key results keeps deriving from them
    expect((float) $mine->refresh()->progress)->toBe(65.0);

    $this->goals->close($mine, 'completed', 'Done');
    expect($mine->refresh()->status)->toBe('completed')->and((float) $mine->progress)->toBe(100.0)
        ->and((float) $team->refresh()->progress)->toBe(100.0);
    expect(fn () => $this->goals->checkIn($mine, 50))->toThrow(RuntimeException::class, 'closed');
    expect(fn () => $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Late', 'parent_id' => $mine->id]))->toThrow(RuntimeException::class, 'closed goal');
});

it('computes progress for each measure type', function () {
    expect(Goal::progressFor('percentage', 0, 100, 40))->toBe(40.0)
        ->and(Goal::progressFor('numeric', 500, 200, 350))->toBe(50.0)
        ->and(Goal::progressFor('numeric', 0, 10, 15))->toBe(100.0)
        ->and(Goal::progressFor('boolean', 0, 1, 0))->toBe(0.0)
        ->and(Goal::progressFor('boolean', 0, 1, 1))->toBe(100.0)
        ->and(Goal::progressFor('numeric', 5, 5, 4))->toBe(0.0);
});
