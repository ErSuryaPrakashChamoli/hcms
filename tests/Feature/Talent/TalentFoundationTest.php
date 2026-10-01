<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Talent\Events\TalentEvent;
use App\Domain\Talent\Models\TalentAssessment;
use App\Domain\Talent\Models\TalentAssessmentModel;
use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Models\TalentProfile;
use App\Domain\Talent\Models\TalentReviewItem;
use App\Domain\Talent\Services\TalentAccess;
use App\Domain\Talent\Services\TalentAssessments;
use App\Domain\Talent\Services\TalentPools;
use App\Domain\Talent\Services\TalentReviews;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = activeEmployee(null, ['career.self', 'career.team', 'succession.team']);
    $this->employee = activeEmployee($this->manager, ['career.self']);
    $this->other = activeEmployee(null, ['career.self']);
    $this->hr = activeEmployee(null, ['talent.view', 'talent.manage', 'talent.assess', 'talent.review', 'talent.confidential']);
    $this->pools = app(TalentPools::class);
    $this->assessments = app(TalentAssessments::class);
    $this->reviews = app(TalentReviews::class);
    $this->pool = TalentPool::create(['code' => 'future-leaders', 'name' => 'Future leaders']);
});

it('adds pool members explicitly with a reason, once while active, and never for oneself', function () {
    Event::fake([TalentEvent::class]);
    $membership = $this->pools->add($this->pool, $this->employee, 'Strong delivery over two cycles', $this->hr->user);

    expect($membership->status)->toBe('active')->and($membership->added_by)->toBe($this->hr->user->id)
        ->and(fn () => $this->pools->add($this->pool, $this->employee, 'Again', $this->hr->user))->toThrow(RuntimeException::class, 'already an active member')
        ->and(fn () => $this->pools->add($this->pool, $this->other, '', $this->hr->user))->toThrow(RuntimeException::class, 'needs a reason')
        ->and(fn () => $this->pools->add($this->pool, $this->hr, 'Me', $this->hr->user))->toThrow(RuntimeException::class, 'never for yourself')
        ->and(fn () => $this->pools->add($this->pool, $this->other, 'Manager wish', $this->manager->user))->toThrow(RuntimeException::class, 'talent.manage')
        ->and(fn () => $membership->update(['reason' => 'rewritten']))->toThrow(RuntimeException::class, 'never edited')
        ->and(fn () => $membership->delete())->toThrow(RuntimeException::class, 'never deleted');

    $this->pools->end($membership, 'Moved to a different pool', $this->hr->user);
    expect($membership->refresh()->status)->toBe('ended')->and($membership->effective_to->toDateString())->toBe('2026-10-05');

    // Ending frees the active key: a later explicit add is a new, separate membership.
    $again = $this->pools->add($this->pool, $this->employee, 'Back after the project', $this->hr->user);
    expect($again->id)->not->toBe($membership->id)->and(TalentPoolMembership::query()->count())->toBe(2);
    Event::assertDispatched(TalentEvent::class, fn (TalentEvent $e) => $e->name === 'talent.pool.membership_changed');
});

it('adds many pool members as one audited bulk operation and skips what it may not add', function () {
    $result = $this->pools->addMany($this->pool, [$this->employee->id, $this->other->id, $this->hr->id], 'Leadership cohort 2026', $this->hr->user);

    expect($result['added'])->toBe(2)->and($result['skipped'])->toHaveCount(1)
        ->and(TalentPoolMembership::query()->count())->toBe(2);
    $operationIds = AuditEvent::query()->where('entity_type', TalentPoolMembership::class)->pluck('operation_id')->unique();
    expect($operationIds)->toHaveCount(1)->and($operationIds->first())->not->toBeNull();
});

it('records talent assessments on a configurable versioned model and never combines them into a score', function () {
    $version = $this->assessments->defaultModelVersion();
    expect(array_keys($version->allowed()))->toBe(['performance', 'potential']);

    expect(fn () => $this->assessments->draft($this->employee, $version, ['performance' => 3], null, null, $this->hr->user))->toThrow(RuntimeException::class, 'Rate every dimension')
        ->and(fn () => $this->assessments->draft($this->employee, $version, ['performance' => 3, 'potential' => 7], null, null, $this->hr->user))->toThrow(RuntimeException::class, 'potential must be one of')
        ->and(fn () => $this->assessments->draft($this->hr, $version, ['performance' => 3, 'potential' => 2], null, null, $this->hr->user))->toThrow(RuntimeException::class, 'never about yourself')
        ->and(fn () => $this->assessments->draft($this->employee, $version, ['performance' => 3, 'potential' => 2], null, null, $this->manager->user))->toThrow(RuntimeException::class, 'talent.assess');

    $draft = $this->assessments->draft($this->employee, $version, ['performance' => 3, 'potential' => 2], 'Consistent outcomes', 'Discussed privately', $this->hr->user);
    $final = $this->assessments->finalize($draft, $this->hr->user);
    expect($final->status)->toBe('final')->and($final->toArray())->not->toHaveKey('confidential_notes')
        ->and(array_keys($final->ratings))->toBe(['performance', 'potential'])
        ->and(fn () => $final->update(['ratings' => ['performance' => 1, 'potential' => 1]]))->toThrow(RuntimeException::class, 'immutable');

    $correction = $this->assessments->finalize($this->assessments->correct($final, ['performance' => 2, 'potential' => 2], 'Calibration evidence', $this->hr->user), $this->hr->user);
    expect($final->refresh()->status)->toBe('superseded')->and($correction->corrects_assessment_id)->toBe($final->id)
        ->and(TalentAssessment::query()->count())->toBe(2);

    // A custom model: any dimensions, not only a 9-box.
    $model = TalentAssessmentModel::create(['code' => 'AGILITY', 'name' => 'Learning agility']);
    expect(fn () => $this->assessments->publishModel($model, [['key' => 'a', 'label' => 'A', 'levels' => [['value' => 1, 'label' => 'x']]]], $this->hr->user))->toThrow(RuntimeException::class, 'at least two levels');
    $v1 = $this->assessments->publishModel($model, [['key' => 'agility', 'label' => 'Agility', 'levels' => [['value' => 1, 'label' => 'Emerging'], ['value' => 2, 'label' => 'Strong']]]], $this->hr->user);
    expect($v1->version)->toBe(1)->and(fn () => $v1->update(['dimensions' => []]))->toThrow(RuntimeException::class, 'immutable');
});

it('reads confidential notes only with talent.confidential, never about oneself, and audits every read', function () {
    $access = app(TalentAccess::class);
    $this->assessments->updateProfile($this->employee, ['confidential_notes' => 'Family relocation in 2027', 'mobility' => 'national'], null, $this->hr->user);
    $profile = TalentProfile::query()->where('employee_id', $this->employee->id)->firstOrFail();

    expect($profile->toArray())->not->toHaveKey('confidential_notes')
        ->and($access->confidential($profile, 'confidential_notes', $this->manager->user))->toBeNull()
        ->and($access->confidential($profile, 'confidential_notes', $this->employee->user))->toBeNull()
        ->and($access->confidential($profile, 'confidential_notes', $this->hr->user))->toBe('Family relocation in 2027')
        ->and(AuditEvent::query()->where('action', 'VIEW')->get()->filter(fn ($e) => ($e->metadata['event'] ?? null) === 'confidential_talent_access'))->toHaveCount(1);

    // Talent records are never visible to the employee or to a manager by relationship alone.
    expect($access->mayViewTalent($this->employee->user, $this->employee->id))->toBeFalse()
        ->and($access->mayViewTalent($this->manager->user, $this->employee->id))->toBeFalse()
        ->and($access->mayViewTalent($this->hr->user, $this->employee->id))->toBeTrue()
        ->and(fn () => $this->assessments->updateProfile($this->employee, ['mobility' => 'teleport'], null, $this->hr->user))->toThrow(RuntimeException::class, 'Unknown mobility')
        ->and(fn () => $this->assessments->updateProfile($this->employee, ['development_priorities' => 'x'], 0, $this->hr->user))->toThrow(RuntimeException::class, 'changed meanwhile');
});

it('respects the organisation scope of talent administrators', function () {
    $north = Location::factory()->create(['company_id' => Company::query()->value('id')]);
    app(AccessScopes::class)->assign($this->hr->user, ['location' => [$north->id]]);

    expect(app(TalentAccess::class)->mayViewTalent($this->hr->user->refresh(), $this->employee->id))->toBeFalse()
        ->and(fn () => $this->pools->add($this->pool, $this->employee, 'Out of scope', $this->hr->user))->toThrow(RuntimeException::class, 'organisation scope');
});

it('runs a talent review where people record decisions that are never executed automatically', function () {
    Event::fake([TalentEvent::class]);
    $reviewer = activeEmployee(null, ['talent.review']);
    $outsider = activeEmployee(null, ['talent.review']);
    $session = $this->reviews->create('Operations talent review', null, [$reviewer->user->id], $this->hr->user, '2026-10-20');
    expect($this->reviews->addEmployees($session, [$this->employee->id, $this->other->id, $this->hr->id], $this->hr->user))->toBe(2);

    $item = TalentReviewItem::query()->where('employee_id', $this->employee->id)->firstOrFail();
    expect(fn () => $this->reviews->recordDecision($item, 'add_to_pool', 'Ready for stretch', $reviewer->user))->toThrow(RuntimeException::class, 'in progress');

    $this->reviews->start($session, $this->hr->user);
    expect(fn () => $this->reviews->recordDecision($item, 'promote', 'x', $reviewer->user))->toThrow(RuntimeException::class, 'Unknown review decision')
        ->and(fn () => $this->reviews->recordDecision($item, 'add_to_pool', '', $reviewer->user))->toThrow(RuntimeException::class, 'needs a reason')
        ->and(fn () => $this->reviews->recordDecision($item, 'add_to_pool', 'x', $outsider->user))->toThrow(RuntimeException::class, 'participants');

    $this->reviews->recordDecision($item, 'add_to_pool', 'Ready for stretch assignments', $reviewer->user);
    expect(fn () => $this->reviews->recordDecision($item->refresh(), 'no_change', 'Changed mind', $reviewer->user))->toThrow(RuntimeException::class, 'already recorded')
        ->and(fn () => $this->reviews->complete($session, 'Summary', $this->hr->user))->toThrow(RuntimeException::class, 'every employee');

    $this->reviews->recordDecision(TalentReviewItem::query()->where('employee_id', $this->other->id)->firstOrFail(), 'retain_and_develop', 'Solid', $this->hr->user);
    $this->reviews->complete($session, 'Two decisions recorded', $this->hr->user);

    expect($session->refresh()->status)->toBe('completed')
        ->and(TalentProfile::query()->where('employee_id', $this->employee->id)->value('latest_review_outcome'))->toBe('add_to_pool')
        // "Add to pool" is a recorded decision: nobody is added until a person does it explicitly.
        ->and(TalentPoolMembership::query()->count())->toBe(0)
        ->and(fn () => $item->refresh()->update(['reason' => 'edit']))->toThrow(RuntimeException::class, 'read-only')
        ->and(fn () => $this->reviews->start($session, $this->hr->user))->toThrow(RuntimeException::class, 'read-only');
    Event::assertDispatched(TalentEvent::class, fn (TalentEvent $e) => $e->name === 'talent.review.completed');
});

it('does not let a mentor relationship grant talent or succession visibility', function () {
    $mentor = activeEmployee(null, ['succession.team', 'career.team']);
    ReportingRelationship::query()->create(['employee_id' => $this->employee->id, 'manager_id' => $mentor->id, 'type' => 'mentor', 'is_primary' => false, 'effective_from' => '2026-01-01']);
    $access = app(TalentAccess::class);

    expect($access->mayViewSuccession($mentor->user, $this->employee->id))->toBeFalse()
        ->and($access->mayViewSuccession($this->manager->user, $this->employee->id))->toBeTrue()
        ->and($access->mayViewTalent($mentor->user, $this->employee->id))->toBeFalse()
        ->and($access->mayViewSuccession($this->employee->user, $this->employee->id))->toBeFalse();
});
