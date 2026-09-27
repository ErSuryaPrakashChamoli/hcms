<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\CareerPassport;
use App\Domain\Performance\Services\Feedback;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\ImprovementPlans;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.feedback', 'task.view']);
    $this->employee = activeEmployee($this->manager);
    $this->peer = activeEmployee($this->manager);
    $this->appraisals = app(Appraisals::class);
    $this->cycle = draftCycle();
});

it('seeds a default scale and competencies for every tenant', function () {
    expect(RatingScale::default()->levels)->toHaveCount(5)
        ->and(RatingScale::default()->labelFor(4.4))->toBe('Exceeds Expectations')
        ->and(RatingScale::default()->labelFor(2.5))->toBe('Developing')
        ->and(Competency::query()->count())->toBe(6);
});

it('launches a cycle for eligible employees, walks the stages, scores, calibrates and finalizes', function () {
    $goals = app(Goals::class);
    $g1 = $goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $this->cycle->id, 'title' => 'Deliver X', 'weight' => 60]);
    $g2 = $goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $this->cycle->id, 'title' => 'Mentor Y', 'weight' => 40]);

    $cycle = $this->appraisals->launch($this->cycle, $this->hr);
    expect($cycle->status)->toBe('active')->and($cycle->current_stage)->toBe('goal_setting')
        ->and($cycle->appraisals()->count())->toBe(3);

    $appraisal = Appraisal::query()->where('employee_id', $this->employee->id)->first();
    expect($appraisal->manager_id)->toBe($this->manager->id)
        ->and($appraisal->reviews()->pluck('type')->sort()->values()->all())->toBe(['manager', 'self']);

    // Reviews cannot be submitted before their stage opens.
    expect(fn () => $this->appraisals->submitReview($appraisal->review('self'), [], [], 3))->toThrow(RuntimeException::class, 'has not opened');
    expect(fn () => $cycle->update(['rating_scale_id' => 999]))->toThrow(RuntimeException::class, 'launched cycle');
    $cycle->refresh();

    $cycle = $this->appraisals->advance($cycle); // self review: goals lock
    expect($g1->refresh()->is_locked)->toBeTrue();
    $this->appraisals->submitReview($appraisal->review('self'), [$g1->id => 4, $g2->id => 5], array_fill_keys($cycle->competency_ids, 4), 4, ['strengths' => 'Shipped']);
    expect(fn () => $this->appraisals->submitReview($appraisal->review('self'), [], [], 3))->toThrow(RuntimeException::class, 'already submitted');

    $cycle = $this->appraisals->advance($cycle); // manager review
    $peerReview = $this->appraisals->addPeerReview($appraisal, $this->peer);
    expect(fn () => $this->appraisals->addPeerReview($appraisal, $this->employee))->toThrow(RuntimeException::class, 'own peer');
    expect(fn () => $this->appraisals->submitReview($appraisal->review('manager'), [$g1->id => 6], [], 3))->toThrow(RuntimeException::class, 'between 1 and 5');

    // Manager: goal 1 = 5 (100%), goal 2 = 3 (50%) → weighted 80%; competencies all 3 (50%); blend 0.7*80 + 0.3*50 = 71% → 1 + 4*0.71 = 3.84.
    $this->appraisals->submitReview($appraisal->review('manager'), [$g1->id => 5, $g2->id => 3], array_fill_keys($cycle->competency_ids, 3), 4, ['strengths' => 'Reliable']);
    $this->appraisals->submitReview($peerReview, [], array_fill_keys($cycle->competency_ids, 4), 4);
    $appraisal->refresh();
    expect((float) $appraisal->goal_score)->toBe(80.0)
        ->and((float) $appraisal->competency_score)->toBe(50.0)
        ->and((float) $appraisal->computed_rating)->toBe(3.84)
        ->and((float) $appraisal->self_rating)->toBe(4.0)
        ->and((float) $appraisal->peer_rating)->toBe(4.0)
        ->and($appraisal->status)->toBe('in_review');

    expect(fn () => $this->appraisals->calibrate($appraisal, 4, 'Too early'))->toThrow(RuntimeException::class, 'not opened');
    $cycle = $this->appraisals->advance($cycle); // calibration
    expect($appraisal->refresh()->status)->toBe('calibration');
    expect(fn () => $this->appraisals->calibrate($appraisal, 4, ''))->toThrow(RuntimeException::class, 'note is required');
    $this->appraisals->calibrate($appraisal, 4, 'Cross-team consistency', $this->hr);
    expect((float) $appraisal->refresh()->calibrated_rating)->toBe(4.0)
        ->and($this->appraisals->distribution($cycle)['Exceeds Expectations'])->toBe(1);

    expect(fn () => $this->appraisals->close($cycle))->toThrow(RuntimeException::class, 'not finalized');
    $this->appraisals->finalize($appraisal, $this->hr, null, 'Strong year', promotion: true);
    $appraisal->refresh();
    expect($appraisal->status)->toBe('finalized')
        ->and((float) $appraisal->final_rating)->toBe(4.0)
        ->and($appraisal->final_label)->toBe('Exceeds Expectations')
        ->and($appraisal->promotion_recommended)->toBeTrue()
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $this->employee->id)->where('category', 'performance')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('module', 'performance')->where('action', 'APPROVED')->exists())->toBeTrue()
        ->and($this->employee->user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains($t, 'is final'))->count())->toBe(1);

    expect(fn () => $this->appraisals->submitReview($peerReview, [], [], 3))->toThrow(RuntimeException::class, 'finalized');
    $this->appraisals->acknowledge($appraisal, 'Thanks');
    expect($appraisal->refresh()->status)->toBe('acknowledged');

    // The other two appraisals have no manager review; finalize them with an explicit rating, then close.
    $cycle->appraisals()->where('id', '!=', $appraisal->id)->get()->each(function (Appraisal $a) use ($cycle) {
        $a->loadMissing('cycle');
        $cycle->refresh();
        expect(fn () => $this->appraisals->finalize($a, $this->hr))->toThrow(RuntimeException::class);
        $this->appraisals->finalize($a, $this->hr, 3.0);
    });
    $cycle = $this->appraisals->advance($cycle); // final stage
    $cycle = $this->appraisals->advance($cycle); // no more stages → close
    expect($cycle->status)->toBe('closed')->and($g1->refresh()->status)->toBe('completed');

    $passport = app(CareerPassport::class)->build($this->employee);
    expect($passport['performance'][0]['label'])->toBe('Exceeds Expectations')->and($passport['goals']['completed'])->toBe(2);
});

it('applies eligibility rules at launch', function () {
    $cycle = draftCycle(['code' => 'ELIG', 'eligibility' => [['field' => 'lifecycle_state', 'operator' => 'equals', 'value' => 'probation']]]);
    $this->employee->update(['lifecycle_state' => 'probation']);

    $cycle = $this->appraisals->launch($cycle);
    expect($cycle->appraisals()->pluck('employee_id')->all())->toBe([$this->employee->id]);
});

it('handles continuous feedback and improvement plans', function () {
    $feedback = app(Feedback::class);
    $request = $feedback->request($this->employee, $this->peer, 'How was my demo?');
    expect($request->status)->toBe('requested')->and($this->peer->user->notifications()->count())->toBe(1);

    $entry = $feedback->give($this->employee, $this->peer, 'praise', 'Great demo', 'public', null, null, $request);
    expect($request->refresh()->status)->toBe('answered')->and($entry->parent_id)->toBe($request->id);
    expect(fn () => $feedback->give($this->employee, $this->employee, 'praise', 'Me'))->toThrow(RuntimeException::class, 'someone else');

    $private = $feedback->give($this->employee, $this->manager, 'constructive', 'Be on time', 'private');
    $outsider = activeEmployee();
    expect($feedback->visibleTo($outsider, false)->pluck('id')->all())->toBe([$entry->id]) // public only
        ->and($feedback->visibleTo($this->manager, false)->pluck('id')->sort()->values()->all())->toBe(collect([$request->id, $entry->id, $private->id])->sort()->values()->all())
        ->and($feedback->visibleTo($this->employee, false)->count())->toBe(3);

    $plans = app(ImprovementPlans::class);
    $plan = $plans->open($this->employee, $this->manager, 'Missed deadlines', [['objective' => 'Deliver sprint commitments', 'measure' => '2 sprints']], '2026-10-01', '2026-11-30');
    expect($plan->status)->toBe('active');
    expect(fn () => $plans->open($this->employee, $this->manager, 'Again', [['objective' => 'x']], '2026-10-01', '2026-11-30'))->toThrow(RuntimeException::class, 'already active');
    $plans->extend($plan, '2026-12-31', 'Sick leave');
    expect($plan->refresh()->status)->toBe('extended');
    $plans->close($plan, 'completed', 'Back on track');
    expect($plan->refresh()->status)->toBe('completed')->and($plan->closed_at)->not->toBeNull();
    expect(fn () => $plans->close($plan, 'completed', 'x'))->toThrow(RuntimeException::class, 'already closed');
});

it('protects appraisal visibility by ownership, team and reviewer', function () {
    $cycle = $this->appraisals->launch($this->cycle);
    $mine = Appraisal::query()->where('employee_id', $this->employee->id)->first();
    $peers = Appraisal::query()->where('employee_id', $this->peer->id)->first();

    expect($this->employee->user->can('view', $mine))->toBeTrue()
        ->and($this->employee->user->can('view', $peers))->toBeFalse()
        ->and($this->manager->user->can('view', $mine))->toBeTrue()
        ->and($this->manager->user->can('view', Appraisal::query()->where('employee_id', $this->manager->id)->first()))->toBeTrue()
        ->and($this->hr->can('view', $peers))->toBeTrue()
        ->and(PerformanceCycle::query()->find($cycle->id)->status)->toBe('active');
});
