<?php

use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceTemplate;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Performance\Services\PerformanceTemplates;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review']);
    $this->employee = activeEmployee($this->manager);
    $this->appraisals = app(Appraisals::class);
});

/** A cycle whose only stage is the final rating, so appraisals can be finalized right after launch. */
function finalOnlyCycle(array $overrides = []): PerformanceCycle
{
    return draftCycle(array_merge(['stages' => [['key' => 'final', 'name' => 'Final rating', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']]], $overrides));
}

it('pins a template version with a rating-scale and competency snapshot when a cycle launches', function () {
    $cycle = $this->appraisals->launch(draftCycle(), $this->hr);
    $version = $cycle->templateVersion;

    expect($version)->not->toBeNull()
        ->and($version->rating_scale_snapshot['levels'])->toBe(RatingScale::default()->levels)
        ->and($version->competency_snapshot)->toHaveCount(3)
        ->and(array_column($version->workflow, 'key'))->toBe($cycle->stageKeys())
        ->and($version->checksum)->toHaveLength(64)
        ->and(Appraisal::query()->where('performance_cycle_id', $cycle->id)->pluck('performance_template_version_id')->unique()->all())->toBe([$version->id])
        ->and($cycle->ratingScale()->labelFor(4))->toBe('Exceeds Expectations');

    // The scale in use cannot change its levels; the pinned version cannot change at all.
    $scale = RatingScale::default();
    expect(fn () => $scale->update(['levels' => [['value' => 1, 'label' => 'Low'], ['value' => 2, 'label' => 'High']]]))->toThrow(RuntimeException::class, 'used by a launched cycle')
        ->and(fn () => $version->update(['weights' => ['goals' => 100]]))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $version->delete())->toThrow(RuntimeException::class, 'never deleted')
        ->and(fn () => $cycle->update(['stages' => []]))->toThrow(RuntimeException::class, 'launched cycle')
        ->and(fn () => $cycle->update(['eligibility' => ['x']]))->toThrow(RuntimeException::class, 'launched cycle');
});

it('publishes numbered template versions and validates their content', function () {
    $templates = app(PerformanceTemplates::class);
    $template = PerformanceTemplate::query()->create(['code' => 'annual', 'name' => 'Annual review']);
    $content = ['rating_scale_id' => RatingScale::default()->id, 'workflow' => [['key' => 'self_review'], ['key' => 'manager_review']], 'goal_rules' => ['required_total_weight' => 100]];

    $v1 = $templates->publish($template, $content, $this->hr);
    $v2 = $templates->publish($template, [...$content, 'weights' => ['goals' => 50, 'competencies' => 50]], $this->hr);

    expect($template->refresh()->code)->toBe('ANNUAL')
        ->and([$v1->version, $v2->version])->toBe([1, 2])
        ->and($v1->checksum)->not->toBe($v2->checksum)
        ->and($v1->requiredGoalWeight())->toBe(100.0)
        ->and($template->latestVersion->id)->toBe($v2->id)
        ->and(fn () => $templates->publish($template, [...$content, 'workflow' => [['key' => 'ranking']]]))->toThrow(RuntimeException::class, 'known stages')
        ->and(fn () => $templates->publish($template, [...$content, 'sections' => ['salary']]))->toThrow(RuntimeException::class, 'Unknown template section')
        ->and(fn () => $templates->publish($template, [...$content, 'weights' => ['goals' => -10]]))->toThrow(RuntimeException::class, 'negative')
        ->and(fn () => $templates->publish($template, [...$content, 'goal_rules' => ['required_total_weight' => 0]]))->toThrow(RuntimeException::class, 'positive');

    // A cycle pinned to a version must run exactly its configuration; applying the template aligns it.
    $cycle = draftCycle(['performance_template_version_id' => $v1->id]);
    expect(fn () => $this->appraisals->launch($cycle, $this->hr))->toThrow(RuntimeException::class, 'differs from template version v1');
    $cycle = $templates->applyTo($cycle->refresh(), $v1);
    $cycle = $this->appraisals->launch($cycle, $this->hr);
    expect($cycle->performance_template_version_id)->toBe($v1->id)
        ->and($cycle->stageKeys())->toBe(['self_review', 'manager_review']);
});

it('schedules, launches, closes with locked appraisals and archives a cycle', function () {
    $cycle = $this->appraisals->schedule(finalOnlyCycle(), '2026-10-01', $this->hr);
    expect($cycle->status)->toBe('scheduled')
        ->and(fn () => $cycle->update(['weights' => ['goals' => 10, 'competencies' => 90]]))->toThrow(RuntimeException::class, 'scheduled or launched');

    $cycle = $this->appraisals->launch($cycle->refresh(), $this->hr);
    expect($cycle->status)->toBe('active')
        ->and(fn () => $this->appraisals->launch($cycle))->toThrow(RuntimeException::class, 'draft or scheduled');

    foreach ($cycle->appraisals()->get() as $appraisal) {
        $this->appraisals->finalize($appraisal, $this->hr, 3.0, 'Solid');
    }
    $cycle = $this->appraisals->close($cycle, $this->hr);
    $appraisal = Appraisal::query()->where('employee_id', $this->employee->id)->firstOrFail();

    expect($cycle->status)->toBe('closed')
        ->and($appraisal->isLocked())->toBeTrue()
        ->and(fn () => $appraisal->update(['employee_comment' => 'late edit']))->toThrow(RuntimeException::class, 'locked')
        ->and(fn () => $appraisal->reviews()->create(['type' => 'peer', 'status' => 'pending']))->toThrow(RuntimeException::class, 'locked')
        ->and(fn () => $this->appraisals->close($cycle))->toThrow(RuntimeException::class, 'Only an active cycle');

    $cycle = $this->appraisals->archive($cycle, $this->hr);
    expect($cycle->status)->toBe('archived')->and($cycle->archived_at)->not->toBeNull()
        ->and(fn () => $cycle->update(['name' => 'Renamed']))->toThrow(RuntimeException::class, 'archived');
});

it('treats only configured relationship types as performance managers', function () {
    $mentor = activeEmployee(null, ['performance.team']);
    $functional = activeEmployee(null, ['performance.team']);
    ReportingRelationship::query()->create(['employee_id' => $this->employee->id, 'manager_id' => $mentor->id, 'type' => 'mentor', 'is_primary' => false, 'effective_from' => '2026-01-01']);
    ReportingRelationship::query()->create(['employee_id' => $this->employee->id, 'manager_id' => $functional->id, 'type' => 'functional', 'is_primary' => false, 'effective_from' => '2026-01-01']);

    $goal = app(Goals::class)->create(['employee_id' => $this->employee->id, 'title' => 'Ship the thing', 'weight' => 100]);
    $relationships = app(PerformanceRelationships::class);

    expect($relationships->reportIds($this->manager)->all())->toBe([$this->employee->id])
        ->and($relationships->manages($functional, $this->employee->id))->toBeTrue()
        ->and($relationships->manages($mentor, $this->employee->id))->toBeFalse()
        ->and(EmployeeOwnedPolicy::isReport($functional->user, $goal))->toBeTrue()
        ->and(EmployeeOwnedPolicy::isReport($mentor->user, $goal))->toBeFalse();

    config(['peopleos.performance.manager_relationship_types' => ['line', 'mentor']]);
    expect(EmployeeOwnedPolicy::isReport($mentor->user, $goal))->toBeTrue()
        ->and(Goal::query()->count())->toBe(1);
});
