<?php

use App\Domain\Development\Models\DevelopmentPlanItem;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Succession\Events\SuccessionEvent;
use App\Domain\Succession\Models\CriticalPositionAssessment;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Services\CriticalPositions;
use App\Domain\Succession\Services\Readiness;
use App\Domain\Succession\Services\SuccessionPlans;
use App\Domain\Talent\Models\TalentDevelopmentAction;
use App\Domain\Talent\Services\TalentAccess;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->head = Designation::query()->create(['name' => 'Head of Operations', 'code' => 'HOPS']);
    $this->incumbent = activeEmployee(null, ['career.self']);
    $this->manager = activeEmployee(null, ['career.self', 'career.team', 'succession.team']);
    $this->candidate = activeEmployee($this->manager, ['career.self']);
    $this->peer = activeEmployee(null, ['career.self']);
    $this->hr = activeEmployee(null, ['succession.view', 'succession.manage', 'succession.assess', 'development.manage', 'talent.confidential']);
    app(AssignPositionAction::class)->handle($this->incumbent, ['designation_id' => $this->head->id], 'promotion', '2026-04-01', 'Appointed');
    $this->positions = app(CriticalPositions::class);
    $this->plans = app(SuccessionPlans::class);
    $this->assessment = ['criticality' => 'critical', 'business_impact' => 'high', 'scarcity' => 'high', 'replacement_difficulty' => 'high', 'operational_dependency' => 'high', 'reason' => 'Runs all plants'];
});

it('designates critical positions by people, with immutable assessments and factual incumbents', function () {
    expect(fn () => $this->positions->designate($this->head, null, 'Head of Operations', $this->assessment, 12, $this->manager->user))->toThrow(RuntimeException::class, 'succession.manage');
    $position = $this->positions->designate($this->head, null, 'Head of Operations', $this->assessment, 12, $this->hr->user);

    expect($position->currentAssessment->criticality)->toBe('critical')
        ->and($position->next_review_on->toDateString())->toBe('2027-10-05')
        ->and(fn () => $this->positions->designate($this->head, null, 'Again', $this->assessment, 12, $this->hr->user))->toThrow(RuntimeException::class, 'already designated critical')
        ->and(fn () => $this->positions->assess($position, [...$this->assessment, 'criticality' => 'apocalyptic'], $this->hr->user))->toThrow(RuntimeException::class, 'Unknown criticality')
        ->and(fn () => $this->positions->assess($position, [...$this->assessment, 'reason' => ''], $this->hr->user))->toThrow(RuntimeException::class, 'needs a reason')
        ->and(fn () => $position->currentAssessment->update(['criticality' => 'low']))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $position->update(['designation_id' => Designation::query()->create(['name' => 'X', 'code' => 'X'])->id]))->toThrow(RuntimeException::class, 'keeps its role')
        ->and($this->positions->incumbents($position->refresh())->pluck('id')->all())->toBe([$this->incumbent->id])
        ->and($this->positions->upcomingIncumbentExit($position))->toBeNull();

    ExitCase::create(['number' => 'EXIT-2026-00001', 'employee_id' => $this->incumbent->id, 'type' => 'resignation', 'status' => 'notice', 'initiated_on' => '2026-09-30', 'last_working_day' => '2026-12-31']);
    expect((string) $this->positions->upcomingIncumbentExit($position))->toStartWith('2026-12-31');

    $this->positions->assess($position, [...$this->assessment, 'criticality' => 'high', 'reason' => 'Second site opened'], $this->hr->user);
    expect(CriticalPositionAssessment::query()->count())->toBe(2)->and($position->refresh()->currentAssessment->criticality)->toBe('high');

    $this->positions->retire($position, 'Role merged', $this->hr->user);
    expect(fn () => $position->refresh()->update(['title' => 'x']))->toThrow(RuntimeException::class, 'read-only')
        ->and(fn () => $position->delete())->toThrow(RuntimeException::class, 'never deleted');
    // Retiring frees the role for a fresh designation.
    expect($this->positions->designate($this->head, null, 'Head of Operations (new)', $this->assessment, 6, $this->hr->user)->id)->not->toBe($position->id);
});

it('keeps one open plan per position and moves it through controlled transitions', function () {
    $position = $this->positions->designate($this->head, null, 'Head of Operations', $this->assessment, 12, $this->hr->user);
    $plan = $this->plans->create($position, ['vacancy_risk' => 'high', 'review_date' => '2027-01-31', 'confidential_notes' => 'Board is aware'], $this->hr->user);

    expect($plan->status)->toBe('draft')->and($plan->toArray())->not->toHaveKey('confidential_notes')
        ->and(fn () => $this->plans->create($position, [], $this->hr->user))->toThrow(RuntimeException::class, 'already has an open succession plan')
        ->and(fn () => $this->plans->create($position, ['vacancy_risk' => 'certain'], $this->hr->user))->toThrow(RuntimeException::class);

    $this->plans->transition($plan, 'active', null, $this->hr->user);
    $stale = SuccessionPlan::query()->findOrFail($plan->id);
    $this->plans->transition($plan, 'under_review', 'Quarterly review', $this->hr->user);
    expect(fn () => $this->plans->transition($stale, 'closed', 'x', $this->hr->user))->toThrow(RuntimeException::class, 'changed meanwhile')
        ->and(fn () => $this->plans->transition($plan, 'draft', null, $this->hr->user))->toThrow(RuntimeException::class, 'cannot move')
        ->and(fn () => $this->plans->transition($plan, 'closed', '', $this->hr->user))->toThrow(RuntimeException::class, 'needs a reason');

    $this->plans->transition($plan, 'closed', 'Position restructured', $this->hr->user);
    expect($plan->refresh()->status)->toBe('closed')->and($plan->active_key)->toBeNull()
        ->and(fn () => $plan->update(['vacancy_risk' => 'low']))->toThrow(RuntimeException::class, 'read-only')
        ->and($this->plans->create($position, [], $this->hr->user)->status)->toBe('draft');
});

it('adds successors by people with reasons, refuses the incumbent and never tells the candidate', function () {
    Event::fake([SuccessionEvent::class]);
    $position = $this->positions->designate($this->head, null, 'Head of Operations', $this->assessment, 12, $this->hr->user);
    $plan = $this->plans->create($position, [], $this->hr->user);

    expect(fn () => $this->plans->addSuccessor($plan, $this->incumbent, null, null, null, $this->hr->user))->toThrow(RuntimeException::class, 'incumbent')
        ->and(fn () => $this->plans->addSuccessor($plan, $this->hr, null, null, null, $this->hr->user))->toThrow(RuntimeException::class, 'never yourself')
        ->and(fn () => $this->plans->addSuccessor($plan, $this->candidate, null, null, null, $this->manager->user))->toThrow(RuntimeException::class, 'succession.manage');

    $successor = $this->plans->addSuccessor($plan, $this->candidate, 'Runs the largest plant', 'Finance exposure', 'Discussed with the CEO', $this->hr->user);
    expect(fn () => $this->plans->addSuccessor($plan, $this->candidate, null, null, null, $this->hr->user))->toThrow(RuntimeException::class, 'already a successor')
        ->and($successor->toArray())->not->toHaveKey('confidential_notes')
        ->and(fn () => $successor->delete())->toThrow(RuntimeException::class, 'never deleted');
    Event::assertDispatched(SuccessionEvent::class, fn (SuccessionEvent $e) => $e->name === 'succession.successor.added'
        && $e->recipientEmployeeIds === [] && $e->recipientUserIds === [$this->hr->user->id]);

    // Candidacy is not visible to the candidate, a peer, or a mentor-like non-manager; it is to the line manager with succession.team.
    $access = app(TalentAccess::class);
    expect($access->mayViewSuccession($this->candidate->user, $this->candidate->id))->toBeFalse()
        ->and($access->mayViewSuccession($this->peer->user, $this->candidate->id))->toBeFalse()
        ->and($access->mayViewSuccession($this->manager->user, $this->candidate->id))->toBeTrue()
        ->and($access->mayManageSuccession($this->manager->user, $this->candidate->id))->toBeFalse();
    $role = Role::factory()->create();
    $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching(['succession.own_candidacy']));
    $this->candidate->user->roles()->attach($role);
    expect($access->mayViewSuccession($this->candidate->user->fresh(), $this->candidate->id))->toBeTrue();

    expect(fn () => $this->plans->removeSuccessor($successor, '', $this->hr->user))->toThrow(RuntimeException::class, 'reason');
    $this->plans->removeSuccessor($successor, 'Moved to another plan', $this->hr->user);
    expect($successor->refresh()->status)->toBe('removed')->and($successor->removal_reason)->toBe('Moved to another plan')
        ->and(fn () => $successor->update(['strengths' => 'x']))->toThrow(RuntimeException::class, 'read-only');
});

it('records readiness as an immutable, effective-dated label that a new assessment supersedes', function () {
    $readiness = app(Readiness::class);
    $position = $this->positions->designate($this->head, null, 'Head of Operations', $this->assessment, 12, $this->hr->user);

    expect(fn () => $readiness->assess($this->candidate, $position->id, null, 'ready_now', 'x', null, $this->manager->user))->toThrow(RuntimeException::class, 'succession.assess')
        ->and(fn () => $readiness->assess($this->hr, $position->id, null, 'ready_now', 'x', null, $this->hr->user))->toThrow(RuntimeException::class, 'never about yourself')
        ->and(fn () => $readiness->assess($this->candidate, $position->id, null, 'born_ready', 'x', null, $this->hr->user))->toThrow(RuntimeException::class, 'Unknown readiness level')
        ->and(fn () => $readiness->assess($this->candidate, null, null, 'ready_now', 'x', null, $this->hr->user))->toThrow(RuntimeException::class, 'critical position or a role');

    $first = $readiness->assess($this->candidate, $position->id, null, '1_2_years', 'Needs plant P&L exposure', 'FY26 review', $this->hr->user);
    expect($first->effective_to->toDateString())->toBe('2027-10-05');
    $this->travelTo('2027-03-01 09:00:00');
    $second = $readiness->assess($this->candidate, $position->id, null, 'lt_1_year', 'Completed P&L rotation', null, $this->hr->user);
    $roleLevel = $readiness->assess($this->candidate, null, $this->head->id, 'longer_term', 'Role-wide view', null, $this->hr->user);

    expect($first->refresh()->status)->toBe('superseded')->and($first->readiness_level)->toBe('1_2_years')
        ->and($readiness->current($this->candidate, $position->id, null)->id)->toBe($second->id)
        ->and($readiness->current($this->candidate, null, $this->head->id)->id)->toBe($roleLevel->id)
        ->and(fn () => $second->update(['readiness_level' => 'ready_now']))->toThrow(RuntimeException::class, 'immutable')
        ->and(ReadinessAssessment::query()->count())->toBe(3);
});

it('turns development actions into Phase 8 development-plan items without assigning learning twice', function () {
    $course = Course::create(['title' => 'Plant finance', 'code' => 'PFIN', 'type' => 'elearning', 'status' => 'published']);
    $position = $this->positions->designate($this->head, null, 'Head of Operations', $this->assessment, 12, $this->hr->user);
    $plan = $this->plans->create($position, [], $this->hr->user);
    $successor = $this->plans->addSuccessor($plan, $this->candidate, null, null, null, $this->hr->user);

    expect(fn () => $this->plans->addDevelopmentAction($successor, 'teleport', 'x', [], $this->hr->user))->toThrow(RuntimeException::class, 'Unknown development action type')
        ->and(fn () => $this->plans->addDevelopmentAction($successor, 'rotation', 'Prepare as successor to the Head of Operations', [], $this->hr->user))->toThrow(RuntimeException::class, 'neutral');
    $action = $this->plans->addDevelopmentAction($successor, 'certification', 'Plant finance certificate', ['course_id' => $course->id], $this->hr->user);
    $second = $this->plans->addDevelopmentAction($successor, 'rotation', 'Six months at the second plant', [], $this->hr->user);

    $item = DevelopmentPlanItem::query()->findOrFail($action->development_plan_item_id);
    expect($item->item_type)->toBe('learning')->and($item->title)->toBe('Plant finance certificate')
        ->and($item->title.$item->plan->title)->not->toContain('successor', 'succession', 'Head of Operations')
        ->and(DevelopmentPlanItem::query()->findOrFail($second->development_plan_item_id)->development_plan_id)->toBe($item->development_plan_id)
        ->and(LearningEnrolment::query()->count())->toBe(0)
        ->and(TalentDevelopmentAction::query()->count())->toBe(2)
        ->and(fn () => $action->update(['action_type' => 'skill']))->toThrow(RuntimeException::class, 'immutable')
        // Succession never changes employment.
        ->and(EmployeePosition::query()->where('employee_id', $this->candidate->id)->count())->toBe(1);
});
