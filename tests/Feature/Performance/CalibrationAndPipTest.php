<?php

use App\Domain\Performance\Contracts\DevelopmentNeedsReader;
use App\Domain\Performance\Contracts\PerformanceOutcomesReader;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\CalibrationAdjustment;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Calibrations;
use App\Domain\Performance\Services\DevelopmentNeeds;
use App\Domain\Performance\Services\ImprovementPlans;

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
    $this->other = activeEmployee();
    $this->appraisals = app(Appraisals::class);
});

it('keeps an immutable calibration history with optimistic concurrency and session population', function () {
    $cycle = $this->appraisals->launch(draftCycle(['stages' => [['key' => 'calibration', 'name' => 'Calibration', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31'], ['key' => 'final', 'name' => 'Final', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']]]), $this->hr);
    $mine = Appraisal::query()->where('employee_id', $this->employee->id)->firstOrFail();
    $theirs = Appraisal::query()->where('employee_id', $this->other->id)->firstOrFail();
    $calibrations = app(Calibrations::class);

    $session = $calibrations->openSession($cycle, 'Engineering', [$mine->id], [$this->hr->id], $this->hr);
    $first = $calibrations->adjust($mine, 3.5, 'Scope of delivery', null, $session, $this->hr);
    $second = $calibrations->adjust($mine, 4.0, 'Cross-team consistency', 3.5, $session, $this->hr);

    expect($first->original_rating)->toBeNull()
        ->and((float) $second->previous_rating)->toBe(3.5)
        ->and((float) $second->adjusted_rating)->toBe(4.0)
        ->and($second->actor_id)->toBe($this->hr->id)
        ->and((float) $mine->refresh()->calibrated_rating)->toBe(4.0)
        ->and(fn () => $calibrations->adjust($mine, 3.0, 'Stale', 3.5, $session, $this->hr))->toThrow(RuntimeException::class, 'changed by someone else')
        ->and(fn () => $calibrations->adjust($theirs, 3.0, 'Not here', null, $session, $this->hr))->toThrow(RuntimeException::class, 'not in the session population')
        ->and(fn () => $calibrations->adjust($mine, 9, 'Out of range', null, $session, $this->hr))->toThrow(RuntimeException::class, 'between 1 and 5')
        ->and(fn () => $calibrations->adjust($mine, 3, ' ', null, $session, $this->hr))->toThrow(RuntimeException::class, 'note is required')
        ->and(fn () => $second->update(['adjusted_rating' => 5]))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $calibrations->openSession($cycle, 'Bad', [999999]))->toThrow(RuntimeException::class, 'appraisals of this cycle');

    // Appraisals::calibrate goes through the same history.
    $this->appraisals->calibrate($theirs, 2.0, 'Missed targets', $this->hr);
    expect(CalibrationAdjustment::query()->where('appraisal_id', $theirs->id)->count())->toBe(1);

    $calibrations->closeSession($session, $this->hr);
    expect(fn () => $calibrations->adjust($mine, 3.0, 'Late', null, $session->refresh(), $this->hr))->toThrow(RuntimeException::class, 'session is closed');

    // The compensation read contract returns nothing until a person finalizes.
    $outcomes = app(PerformanceOutcomesReader::class);
    expect($outcomes->finalOutcome($this->employee->id, $cycle->id))->toBeNull();
    $this->appraisals->finalize($mine->refresh(), $this->hr, null, 'Calibrated');
    expect($outcomes->finalOutcome($this->employee->id, $cycle->id))->toMatchArray(['cycle_code' => 'FY26', 'final_rating' => 4.0, 'final_label' => 'Exceeds Expectations', 'template_version_id' => $cycle->performance_template_version_id]);
});

it('moves an improvement plan through its transition map with checkpoints and a read-only close', function () {
    $plans = app(ImprovementPlans::class);
    $plan = $plans->open($this->employee, $this->manager, 'Missed deadlines', [['objective' => 'Deliver commitments']], '2026-10-01', '2026-11-30', null, $this->hr, draft: true);

    expect($plan->status)->toBe('draft')
        ->and(fn () => $plans->open($this->employee, $this->manager, 'Again', [['objective' => 'x']], '2026-10-01', '2026-11-30'))->toThrow(RuntimeException::class, 'already active')
        ->and(fn () => $plans->open($this->other, null, 'Other', [['objective' => 'x']], '2026-10-01', '2026-11-30', null, $this->manager->user))->toThrow(RuntimeException::class, 'Only HR or a manager')
        ->and(fn () => $plans->open($this->other, null, 'Backwards', [['objective' => 'x']], '2026-10-01', '2026-09-01'))->toThrow(RuntimeException::class, 'end before it starts')
        ->and(fn () => $plans->close($plan, 'completed', 'Too early'))->toThrow(RuntimeException::class, 'cannot move from draft to completed');

    $plan = $plans->activate($plan, $this->hr);
    $checkpoint = $plans->addCheckpoint($plan, 'Mid-point review', '2026-10-31');
    expect(fn () => $plans->addCheckpoint($plan, 'Outside', '2027-01-15'))->toThrow(RuntimeException::class, 'within the plan period');
    $plans->reviewCheckpoint($checkpoint, 'partially_met', 'Two of three delivered', $this->manager->user);
    expect(fn () => $plans->reviewCheckpoint($checkpoint->refresh(), 'met', 'again'))->toThrow(RuntimeException::class, 'already reviewed');

    expect(fn () => $plans->extend($plan, '2026-11-15', 'Shorter'))->toThrow(RuntimeException::class, 'after the current end date');
    $plan = $plans->extend($plan, '2026-12-31', 'Medical leave', $this->manager->user);
    $stale = ImprovementPlan::query()->findOrFail($plan->id);
    $plan = $plans->close($plan, 'completed', 'Back on track', $this->hr);
    expect($plan->status)->toBe('completed')
        ->and(fn () => $plans->closeOut($stale, 'stale'))->toThrow(RuntimeException::class, 'changed by someone else');

    $plan = $plans->closeOut($plan, 'Closed after review', $this->hr);
    expect($plan->status)->toBe('closed')
        ->and(fn () => $plan->update(['outcome' => 'rewrite']))->toThrow(RuntimeException::class, 'read-only')
        ->and($plan->checkpoints()->count())->toBe(1);

    // Cancelling is a distinct outcome, and a new plan can start once the previous one is final.
    $next = $plans->open($this->employee, $this->manager, 'New concern', [['objective' => 'y']], '2027-01-01', '2027-02-28');
    expect($plans->close($next, 'cancelled', 'Raised in error')->status)->toBe('cancelled');
});

it('records development needs for a future Learning module through a read contract', function () {
    $needs = app(DevelopmentNeeds::class);
    $needs->record($this->employee, 'Stakeholder communication', 'check_in', null, null, 'high', null, $this->manager->user);

    expect(fn () => $needs->record($this->other, 'Not mine', 'manual', null, null, 'medium', null, $this->manager->user))->toThrow(RuntimeException::class, 'employees you manage')
        ->and(fn () => $needs->record($this->employee, 'X', 'course'))->toThrow(RuntimeException::class, 'Unknown development-need source')
        ->and(app(DevelopmentNeedsReader::class)->openNeedsFor($this->employee->id))->toHaveCount(1)
        ->and(app(DevelopmentNeedsReader::class)->openNeedsFor($this->employee->id)[0])->toMatchArray(['title' => 'Stakeholder communication', 'priority' => 'high', 'source_type' => 'check_in']);
});
