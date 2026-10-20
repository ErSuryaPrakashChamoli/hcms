<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyQuestion;
use App\Domain\Engagement\Models\SurveyResponse;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Engagement\Services\Surveys;
use App\Domain\Identity\Scopes\AccessScope;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/EngagementTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actors = engagementActors();
    $this->staff = engagementTeam(6);
});

it('takes a survey version through draft, review, approval, schedule and open with a pinned audience snapshot', function () {
    $surveys = app(Surveys::class);
    $survey = $surveys->create(['code' => 'pulse-q4', 'name' => 'Q4 pulse', 'closes_at' => '2026-10-20 18:00:00', 'opens_at' => '2026-10-06 09:00:00', 'audience_criteria' => ['lifecycle_states' => ['active']]], $this->actors['preparer']);
    expect($survey->code)->toBe('PULSE-Q4');
    $version = $survey->versions()->first();
    expect($version->status)->toBe('draft')->and($version->anonymity_mode)->toBe('anonymous')->and($version->response_rule)->toBe('once');

    expect(fn () => $surveys->submit($version, $this->actors['preparer']))->toThrow(EngagementRuleViolation::class, 'at least one question');
    $surveys->saveQuestion($version, ['key' => 'mood', 'type' => 'likert', 'prompt' => 'I enjoy my work', 'required' => true, 'admin_metadata' => ['driver' => 'enjoyment'], 'scoring' => ['weight' => 2]], $this->actors['preparer']);
    $version = $surveys->submit($version->refresh(), $this->actors['preparer']);
    expect($version->status)->toBe('in_review')->and($version->audience_criteria)->toBe(['lifecycle_states' => ['active']]);

    // Content is frozen from submission on; questions too.
    expect(fn () => $version->update(['intro' => 'changed']))->toThrow(RuntimeException::class)
        ->and(fn () => SurveyQuestion::query()->first()->update(['prompt' => 'changed']))->toThrow(RuntimeException::class)
        ->and(fn () => $surveys->saveQuestion($version, ['key' => 'x', 'type' => 'text', 'prompt' => 'x'], $this->actors['preparer']))->toThrow(EngagementRuleViolation::class);

    // Separation of duties: the preparer never approves.
    $both = tenantUser($this->tenant, ['engagement.manage', 'engagement.approve']);
    $draft2 = $surveys->create(['code' => 'other', 'name' => 'Other', 'closes_at' => '2026-10-20', 'audience_criteria' => ['lifecycle_states' => ['active']]], $both)->versions()->first();
    $surveys->saveQuestion($draft2, ['key' => 'q', 'type' => 'yes_no', 'prompt' => 'Q?'], $both);
    $draft2 = $surveys->submit($draft2->refresh(), $both);
    expect(fn () => $surveys->approve($draft2, null, $both))->toThrow(EngagementRuleViolation::class, 'prepared it cannot approve');

    $version = $surveys->approve($version, 'Looks right', $this->actors['approver']);
    expect($version->status)->toBe('approved')->and($version->checksum)->toHaveLength(64);
    $version = $surveys->publish($version, $this->actors['preparer']);
    expect($version->status)->toBe('scheduled');

    $this->travelTo('2026-10-06 09:30:00');
    $version = $surveys->open($version->refresh());
    expect($version->status)->toBe('open')->and($version->eligible_count)->toBe(6)
        ->and(SurveyParticipation::query()->withoutGlobalScope(AccessScope::class)->where('survey_version_id', $version->id)->count())->toBe(6)
        ->and(AuditEvent::query()->where('entity_id', (string) $version->id)->pluck('action')->map->value->all())->toContain('SURVEY_APPROVED', 'SURVEY_PUBLISHED', 'SURVEY_OPENED', 'AUDIENCE_USED');

    // Opening twice changes nothing (snapshot taken once).
    $surveys->open($version->refresh());
    expect(SurveyParticipation::query()->withoutGlobalScope(AccessScope::class)->where('survey_version_id', $version->id)->count())->toBe(6);

    // A new hire after opening is not eligible: the snapshot decides, not today's organisation.
    $late = engagementStaff();
    expect(fn () => app(SurveyResponses::class)->submit($version, $late->user, ['mood' => '4']))->toThrow(EngagementRuleViolation::class, 'not invited');
});

it('pins responses to their version and keeps them when a correction version is created', function () {
    $v1 = openEngagementSurvey($this->actors);
    $responses = app(SurveyResponses::class);
    $responses->submit($v1, $this->staff[0]->user, ['mood' => '4', 'tools' => ['laptop', 'wiki'], 'comment' => 'Good']);

    $surveys = app(Surveys::class);
    $surveys->close($v1, $this->actors['preparer']);
    $v2 = $surveys->newVersion($v1->survey, $this->actors['preparer']);
    expect($v2->version)->toBe(2)->and($v2->status)->toBe('draft')->and($v2->questions()->count())->toBe(3);
    $surveys->saveQuestion($v2, ['key' => 'mood', 'type' => 'rating', 'prompt' => 'Rate your week', 'scale' => ['min' => 1, 'max' => 10]], $this->actors['preparer'], $v2->questions()->where('key', 'mood')->first());

    $response = SurveyResponse::query()->first();
    expect($response->survey_version_id)->toBe($v1->id)
        ->and($response->answers()->with('question')->get()->pluck('question.type')->unique()->sort()->values()->all())->toBe(['likert', 'multiple_choice', 'text'])
        ->and(SurveyQuestion::query()->where('survey_version_id', $v1->id)->where('key', 'mood')->value('type'))->toBe('likert');
});

it('enforces one response per person for anonymous surveys and keeps the response unlinked', function () {
    $version = openEngagementSurvey($this->actors);
    $responses = app(SurveyResponses::class);
    $first = $responses->submit($version, $this->staff[0]->user, ['mood' => '5']);
    $again = $responses->submit($version, $this->staff[0]->user, ['mood' => '1']);

    expect($first)->toBe(['status' => 'submitted', 'response_id' => null])
        ->and($again)->toBe(['status' => 'already_submitted', 'response_id' => null])
        ->and(SurveyResponse::query()->count())->toBe(1);
    $row = SurveyResponse::query()->first();
    expect($row->employee_id)->toBeNull()->and($row->submitted_on)->toBeNull()->and($row->idempotency_key)->toBeNull()
        ->and(array_keys($row->getAttributes()))->not->toContain('created_at', 'updated_at', 'participation_id', 'user_id');
    expect(fn () => $row->update(['group_key' => 'x']))->toThrow(RuntimeException::class)
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class);

    // Validation: required, unknown keys, bad options.
    expect(fn () => $responses->submit($version, $this->staff[1]->user, []))->toThrow(EngagementRuleViolation::class, 'Please answer')
        ->and(fn () => $responses->submit($version, $this->staff[1]->user, ['mood' => '9']))->toThrow(EngagementRuleViolation::class, 'not an option')
        ->and(fn () => $responses->submit($version, $this->staff[1]->user, ['mood' => '3', 'secret' => 'x']))->toThrow(EngagementRuleViolation::class, 'Unknown question');

    // Closed: no more responses; unsubmitted participations expire.
    app(Surveys::class)->close($version, $this->actors['preparer']);
    expect(fn () => $responses->submit($version, $this->staff[1]->user, ['mood' => '3']))->toThrow(EngagementRuleViolation::class, 'not open')
        ->and(SurveyParticipation::query()->withoutGlobalScope(AccessScope::class)->where('status', 'expired')->count())->toBe(5);
});

it('lets identified surveys be corrected by the respondent while open, superseding not overwriting', function () {
    $version = openEngagementSurvey($this->actors, ['anonymity_mode' => 'identified']);
    $responses = app(SurveyResponses::class);
    $result = $responses->submit($version, $this->staff[0]->user, ['mood' => '2'], 'key-1');
    expect($result['status'])->toBe('submitted')->and($result['response_id'])->not->toBeNull();
    expect($responses->submit($version, $this->staff[0]->user, ['mood' => '2'], 'key-1'))->toBe(['status' => 'already_submitted', 'response_id' => $result['response_id']]);

    $old = SurveyResponse::query()->find($result['response_id']);
    $new = $responses->correct($old, $this->staff[0]->user, ['mood' => '4'], 'Misclicked');
    expect($old->refresh()->status)->toBe('superseded')->and($old->active_key)->toBeNull()
        ->and($new->supersedes_id)->toBe($old->id)->and(SurveyResponse::query()->count())->toBe(2)
        ->and($responses->myIdentifiedResponses($version, $this->staff[0]->user))->toHaveCount(1);
    expect(fn () => $responses->correct($new, $this->staff[1]->user, ['mood' => '1'], 'x'))->toThrow(EngagementRuleViolation::class);

    $anonymous = openEngagementSurvey($this->actors);
    $responses->submit($anonymous, $this->staff[0]->user, ['mood' => '3']);
    expect(fn () => $responses->correct(SurveyResponse::query()->where('survey_version_id', $anonymous->id)->first(), $this->staff[0]->user, ['mood' => '1'], 'x'))
        ->toThrow(EngagementRuleViolation::class, 'final');
});

it('refuses settings that would weaken anonymity or exceed the audience scope', function () {
    $surveys = app(Surveys::class);
    expect(fn () => $surveys->create(['code' => 'a', 'name' => 'A', 'anonymity_mode' => 'anonymous', 'response_rule' => 'multiple'], $this->actors['preparer']))->toThrow(EngagementRuleViolation::class, 'exactly one response')
        ->and(fn () => $surveys->create(['code' => 'b', 'name' => 'B', 'result_visibility' => ['managers' => true]], $this->actors['preparer']))->toThrow(EngagementRuleViolation::class, 'line manager')
        ->and(fn () => $surveys->create(['code' => 'c', 'name' => 'C', 'audience_criteria' => ['salary_above' => 1]], $this->actors['preparer']))->toThrow(EngagementRuleViolation::class, 'Unknown audience');

    $version = $surveys->create(['code' => 'd', 'name' => 'D', 'closes_at' => '2026-10-30'], $this->actors['preparer'])->versions()->first();
    $surveys->saveQuestion($version, ['key' => 'q', 'type' => 'yes_no', 'prompt' => 'Q?'], $this->actors['preparer']);
    expect(fn () => $surveys->submit($version->refresh(), $this->actors['preparer']))->toThrow(EngagementRuleViolation::class, 'audience');
    expect(SurveyVersion::query()->count())->toBe(1);
});
