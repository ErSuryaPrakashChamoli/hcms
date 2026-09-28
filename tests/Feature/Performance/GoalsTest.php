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

it('keeps an immutable progress history with previous values, source and measurement', function () {
    $goal = $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Close 40 deals', 'measure_type' => 'numeric', 'start_value' => 0, 'target_value' => 40, 'weight' => 50]);

    $first = $this->goals->checkIn($goal, 10, 'Q1', 'on_track', null, 'manual', 'CRM closed-won count');
    $second = $this->goals->checkIn($goal, 30, 'Q2', 'at_risk', null, 'api');

    expect((float) $first->previous_value)->toBe(0.0)->and((float) $first->value)->toBe(10.0)
        ->and((float) $first->previous_progress)->toBe(0.0)->and((float) $first->progress)->toBe(25.0)
        ->and($first->measurement)->toBe('CRM closed-won count')
        ->and((float) $second->previous_value)->toBe(10.0)->and((float) $second->previous_progress)->toBe(25.0)
        ->and($second->source)->toBe('api')->and($second->created_by)->not->toBeNull()
        ->and(fn () => $second->update(['value' => 40]))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $first->delete())->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $this->goals->checkIn($goal, 35, null, null, null, 'rumour'))->toThrow(RuntimeException::class, 'Unknown progress source')
        ->and(fn () => $this->goals->checkIn($goal, 35, null, 'maybe'))->toThrow(RuntimeException::class, 'Unknown confidence');
});

it('validates weights, dates, duplicates, alignment and ownership', function () {
    $company = $this->goals->create(['level' => 'company', 'title' => 'Grow']);
    $team = $this->goals->create(['level' => 'team', 'title' => 'Ship', 'parent_id' => $company->id]);
    $mine = $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Mine', 'weight' => 30, 'parent_id' => $team->id]);

    expect(fn () => $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Negative', 'weight' => -5]))->toThrow(RuntimeException::class, 'cannot be negative')
        ->and(fn () => $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Backwards', 'start_date' => '2026-10-01', 'due_date' => '2026-09-01']))->toThrow(RuntimeException::class, 'due date')
        ->and(fn () => $this->goals->create(['employee_id' => $this->employee->id, 'title' => ' mine ', 'weight' => 10]))->toThrow(RuntimeException::class, 'already exists')
        ->and(fn () => $this->goals->create(['level' => 'company', 'title' => 'Upward', 'parent_id' => $mine->id]))->toThrow(RuntimeException::class, 'own level or above')
        ->and(fn () => $this->goals->update($company, ['parent_id' => $team->id]))->toThrow(RuntimeException::class, 'own level or above')
        ->and(fn () => $this->goals->update($team, ['parent_id' => $this->goals->create(['level' => 'team', 'title' => 'Sub-team', 'parent_id' => $team->id])->id]))->toThrow(RuntimeException::class, 'circular')
        ->and(fn () => $this->goals->update($team, ['parent_id' => $team->id]))->toThrow(RuntimeException::class, 'circular')
        ->and(fn () => $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'KR', 'weight' => 10], [['title' => 'bad', 'weight' => -1]]))->toThrow(RuntimeException::class, 'key result weight');

    // Ownership: an employee sets their own goals only; organisation goals need performance.manage.
    $other = activeEmployee();
    expect(fn () => $this->goals->create(['employee_id' => $other->id, 'title' => 'Not mine'], [], $this->employee->user))->toThrow(RuntimeException::class, 'yourself or for employees you manage')
        ->and(fn () => $this->goals->create(['level' => 'company', 'title' => 'Org'], [], $this->employee->user))->toThrow(RuntimeException::class, 'organisation goals')
        ->and($this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Mine too', 'weight' => 20], [], $this->employee->user)->exists)->toBeTrue();

    // Optimistic concurrency: a stale editor is refused.
    $loaded = $mine->refresh()->lock_version;
    $this->goals->update($mine, ['title' => 'Mine (renamed)'], $loaded);
    expect(fn () => $this->goals->update($mine, ['weight' => 40], $loaded))->toThrow(RuntimeException::class, 'changed by someone else')
        ->and($mine->refresh()->lock_version)->toBe($loaded + 1);
});

it('enforces the configured weight total of a cycle and submits the plan', function () {
    $cycle = draftCycle(['settings' => ['required_goal_weight' => 100]]);
    $base = ['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'status' => 'draft'];
    $this->goals->create([...$base, 'title' => 'A', 'weight' => 60]);
    $b = $this->goals->create([...$base, 'title' => 'B', 'weight' => 30]);

    expect(fn () => $this->goals->create([...$base, 'title' => 'C', 'weight' => 20]))->toThrow(RuntimeException::class, 'this cycle allows 100')
        ->and(fn () => $this->goals->submitPlan($this->employee, $cycle->id))->toThrow(RuntimeException::class, 'add up to 90')
        ->and($this->goals->planStatus($this->employee, $cycle->id))->toMatchArray(['total' => 90.0, 'required' => 100.0, 'valid' => false]);

    $this->goals->update($b, ['weight' => 40]);
    expect($this->goals->submitPlan($this->employee, $cycle->id))->toBe(2)
        ->and(Goal::query()->where('performance_cycle_id', $cycle->id)->pluck('status')->unique()->all())->toBe(['active']);

    // A cycle without a configured total accepts any non-negative weights (no hard-coded 100%).
    $free = draftCycle(['code' => 'FREE']);
    $this->goals->create([...$base, 'performance_cycle_id' => $free->id, 'title' => 'X', 'weight' => 80]);
    expect($this->goals->create([...$base, 'performance_cycle_id' => $free->id, 'title' => 'Y', 'weight' => 80])->exists)->toBeTrue()
        ->and($this->goals->planStatus($this->employee, $free->id)['valid'])->toBeTrue();
});

it('freezes the definition of a goal locked for review', function () {
    $goal = $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Locked', 'weight' => 50]);
    $goal->update(['is_locked' => true]);

    expect(fn () => $goal->refresh()->update(['weight' => 60]))->toThrow(RuntimeException::class, 'locked for review')
        ->and($this->goals->checkIn($goal, 50)->exists)->toBeTrue();
});
