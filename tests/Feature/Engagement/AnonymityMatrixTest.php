<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Engagement\Events\SurveyResponseSubmitted;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\EngagementIdentity;
use App\Domain\Engagement\Models\SurveyAnswer;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyResponse;
use App\Domain\Engagement\Services\ConfidentialIdentities;
use App\Domain\Engagement\Services\EngagementAnalytics;
use App\Domain\Engagement\Services\SurveyNotices;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Engagement\Services\Surveys;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/EngagementTestHelpers.php';

/*
| Phase 13 §53 — the anonymity test matrix. Who might try to learn "who said what" in an anonymous
| survey, and through which path: employee, manager, HR, API, the database schema itself, small
| groups, filter combinations, free text, timing, ordering, audit, notifications and events.
*/

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actors = engagementActors();
    $this->manager = engagementStaff(null, null, ['engagement.participate', 'engagement.team_results']);
    $this->team = engagementTeam(6, null, $this->manager);
    // A second team, so that the line-manager breakdown has two groups of at least k (otherwise there are no groups at all).
    $this->otherManager = engagementStaff(null, null, ['engagement.participate', 'engagement.team_results']);
    $this->others = engagementTeam(5, null, $this->otherManager);
    $this->version = openEngagementSurvey($this->actors, ['breakdown_dimension' => 'manager', 'result_visibility' => ['hr' => true, 'managers' => true, 'employees' => true]]);
    $this->god = tenantUser($this->tenant, ['*']);
});

function submitTeam(array $team, $version): void
{
    foreach ($team as $i => $employee) {
        app(SurveyResponses::class)->submit($version, $employee->user, ['mood' => (string) (($i % 5) + 1), 'comment' => "Private thought {$i}"]);
    }
}

it('stores no link between an anonymous response and its author in any column, and no time or order', function () {
    Event::fake([SurveyResponseSubmitted::class]);
    submitTeam($this->team, $this->version);

    // Schema: the content side has no person, participation, user, token or time column at all.
    $responseColumns = Schema::getColumnListing('survey_responses');
    $answerColumns = Schema::getColumnListing('survey_answers');
    expect($responseColumns)->not->toContain('participation_id', 'user_id', 'token', 'ip_address', 'created_at', 'updated_at')
        ->and($answerColumns)->not->toContain('employee_id', 'participation_id', 'user_id', 'created_at', 'updated_at')
        ->and(Schema::getColumnListing('survey_participations'))->not->toContain('response_id', 'created_at', 'updated_at');

    $rows = SurveyResponse::query()->get();
    expect($rows)->toHaveCount(6)
        ->and($rows->pluck('employee_id')->filter()->all())->toBe([])
        ->and($rows->pluck('submitted_on')->filter()->all())->toBe([])
        ->and($rows->pluck('idempotency_key')->filter()->all())->toBe([])
        ->and(EngagementIdentity::query()->count())->toBe(0);
    // Random version-4 UUIDs: no time component, so ids reveal nothing about submission order.
    foreach ([...$rows->pluck('id'), ...SurveyAnswer::query()->pluck('id')] as $id) {
        expect($id[14])->toBe('4');
    }

    // Participation keeps a date, never a time of day.
    $submittedOn = SurveyParticipation::query()->withoutGlobalScope(AccessScope::class)->where('status', 'submitted')->pluck('submitted_on')->unique();
    expect($submittedOn->map(fn ($d) => $d->format('H:i:s'))->unique()->all())->toBe(['00:00:00']);

    // The domain event carries the version and mode only.
    Event::assertDispatched(SurveyResponseSubmitted::class, fn (SurveyResponseSubmitted $e) => array_keys(get_object_vars($e)) === ['tenantId', 'surveyVersionId', 'mode']);
});

it('audits an anonymous submission without actor, IP, user agent, request id, response id or answers', function () {
    submitTeam(array_slice($this->team, 0, 2), $this->version);
    $events = AuditEvent::query()->where('action', 'SURVEY_RESPONSE_SUBMITTED')->get();
    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event->actor_id)->toBeNull()->and($event->actor_name)->toBeNull()->and($event->ip_address)->toBeNull()
            ->and($event->user_agent)->toBeNull()->and($event->request_id)->toBeNull()->and($event->source)->toBe('anonymous')
            ->and($event->entity_type)->toContain('SurveyVersion')->and($event->metadata)->toBe(['mode' => 'anonymous']);
    }
    $responseIds = SurveyResponse::query()->pluck('id')->all();
    $all = AuditEvent::query()->get()->map(fn ($e) => json_encode($e->getAttributes()))->implode("\n");
    foreach ($responseIds as $id) {
        expect($all)->not->toContain($id);
    }
    expect($all)->not->toContain('Private thought');
    // No audit event was written by (or about) the respondents during submission.
    $respondentUsers = collect(array_slice($this->team, 0, 2))->map(fn ($e) => $e->user_id)->all();
    expect(AuditEvent::query()->where('action', 'SURVEY_RESPONSE_SUBMITTED')->whereIn('actor_id', $respondentUsers)->exists())->toBeFalse();
});

it('gives an employee no way to see anyone else\'s anonymous response or participation', function () {
    submitTeam($this->team, $this->version);
    $colleague = $this->team[0]->user->fresh();
    expect($colleague->can('viewAny', SurveyResponse::class))->toBeFalse()
        ->and($colleague->can('view', SurveyResponse::query()->first()))->toBeFalse()
        ->and($colleague->can('viewAny', SurveyParticipation::class))->toBeFalse()
        ->and(app(SurveyResponses::class)->myIdentifiedResponses($this->version, $colleague))->toHaveCount(0);
    // The employee's own survey list shows their own status only.
    expect(app(SurveyResponses::class)->mySurveys($colleague)->pluck('participation.employee_id')->unique()->all())->toBe([$this->team[0]->id]);
    // Employees see overall results only, after closing, never groups or comments.
    app(Surveys::class)->close($this->version, $this->actors['preparer']);
    $view = app(EngagementAnalytics::class)->results($this->version->refresh(), $colleague);
    expect($view['view'])->toBe('employee')->and($view['groups'])->toBe([])
        ->and(collect($view['questions'])->pluck('groups')->flatten()->all())->toBe([]);
    expect(fn () => app(EngagementAnalytics::class)->comments($this->version, $this->version->questions()->where('key', 'comment')->first(), $colleague))->toThrow(EngagementRuleViolation::class);
});

it('gives a manager only an aggregate of their team above the threshold, never who answered what', function () {
    submitTeam($this->team, $this->version);
    $manager = $this->manager->user->fresh();
    // While open: nothing (no live differencing as people submit).
    expect(app(EngagementAnalytics::class)->results($this->version, $manager)['questions'])->toBe([]);
    app(Surveys::class)->close($this->version, $this->actors['preparer']);
    $view = app(EngagementAnalytics::class)->results($this->version->refresh(), $manager);
    $encoded = json_encode($view);
    expect($view['view'])->toBe('manager')->and($view['respondents'])->toBe(6);
    foreach ([...SurveyResponse::query()->pluck('id'), ...collect($this->team)->pluck('id')->map(fn ($id) => '"employee_id":'.$id)] as $needle) {
        expect($encoded)->not->toContain((string) $needle);
    }
    expect($encoded)->not->toContain('Private thought');
    expect(fn () => app(EngagementAnalytics::class)->participation($this->version, $manager))->toThrow(EngagementRuleViolation::class);
});

it('gives HR — even with every permission — aggregates only, and no way to identify an anonymous respondent', function () {
    submitTeam([...$this->team, ...$this->others], $this->version);
    app(Surveys::class)->close($this->version, $this->actors['preparer']);
    $results = app(EngagementAnalytics::class)->results($this->version->refresh(), $this->god);
    $comments = app(EngagementAnalytics::class)->comments($this->version, $this->version->questions()->where('key', 'comment')->first(), $this->god);
    expect($comments)->toHaveCount(11)->and(collect($comments)->pluck('handle')->filter()->all())->toBe([]);
    $encoded = json_encode([$results, app(EngagementAnalytics::class)->participation($this->version, $this->god)]);
    foreach (SurveyResponse::query()->pluck('id') as $id) {
        expect($encoded)->not->toContain($id);
    }
    // There is no reveal for anonymous responses — for anyone.
    $anyResponse = SurveyResponse::query()->value('id');
    expect(fn () => app(ConfidentialIdentities::class)->reveal('survey_response', $anyResponse, 'Serious safety concern raised', $this->god))
        ->toThrow(EngagementRuleViolation::class, 'anonymous responses never can');
    expect($this->god->can('viewAny', SurveyResponse::class))->toBeFalse()->and($this->god->can('viewAny', EngagementIdentity::class))->toBeFalse();
});

it('keeps the API from reconstructing identity through ids, participation, pagination, filters or timing', function () {
    submitTeam($this->team, $this->version);
    $key = app(ApiKeys::class)->issue('Intranet', ['engagement.read']);
    auth()->logout();
    actAsTenant(null);
    $api = fn (string $path) => $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/engagement/'.$path);
    $code = $this->version->survey->code;

    // Before closing: no results.
    $api("surveys/{$code}/versions/1/results")->assertOk()->assertJsonPath('data.released', false)->assertJsonPath('data.questions', []);
    // Participation: counts only.
    $participation = $api("surveys/{$code}/participation")->assertOk()->json('data');
    expect($participation)->toBe([['version' => 1, 'status' => 'open', 'eligible' => 13, 'submitted' => 6, 'response_rate' => 46.2]]);
    // Per-employee participation is never disclosed for anonymous surveys.
    foreach ($this->team as $employee) {
        $api('my-surveys?employee='.$employee->employee_code)->assertOk()->assertJsonPath('data.0.participation', 'not_disclosed');
    }
    $api('my-surveys?employee='.$this->manager->employee_code)->assertOk()->assertJsonPath('data.0.participation', 'not_disclosed');

    actAsTenant($this->tenant);
    app(Surveys::class)->close($this->version, $this->actors['preparer']);
    actAsTenant(null);
    // Filters and pagination parameters cannot narrow results: unknown parameters are ignored, no groups exist in the API.
    $plain = $api("surveys/{$code}/versions/1/results")->assertOk()->json('data');
    $filtered = $api("surveys/{$code}/versions/1/results?group=manager:{$this->manager->id}&department=1&per_page=1&page=2")->assertOk()->json('data');
    expect($filtered)->toBe($plain)->and($plain['respondents'])->toBe(6)->and(array_key_exists('groups', $plain))->toBeFalse()
        ->and(collect($plain['questions'])->pluck('key')->all())->not->toContain('comment');
    $everything = json_encode([$plain, $api('surveys')->json(), $api("surveys/{$code}")->json()]);
    foreach (SurveyResponse::query()->withoutGlobalScopes()->pluck('id') as $id) {
        expect($everything)->not->toContain($id);
    }
    expect($everything)->not->toContain('Private thought')->not->toContain('admin_metadata')->not->toContain('audience_criteria');
});

it('lets confidential identity be revealed only with permission, a reason, in scope, one item at a time, audited without naming the person', function () {
    $staff = engagementTeam(10);
    $confidential = openEngagementSurvey($this->actors, ['anonymity_mode' => 'confidential'], null, 'CONF');
    foreach ($staff as $i => $e) {
        app(SurveyResponses::class)->submit($confidential, $e->user, ['mood' => '3', 'comment' => "Concern {$i}"]);
    }
    app(Surveys::class)->close($confidential, $this->actors['preparer']);
    expect(SurveyResponse::query()->where('survey_version_id', $confidential->id)->whereNotNull('employee_id')->count())->toBe(0)
        ->and(EngagementIdentity::query()->count())->toBe(10);

    $investigator = tenantUser($this->tenant, ['engagement.analytics', 'engagement.comments', 'engagement.confidential_identity']);
    $comments = app(EngagementAnalytics::class)->comments($confidential->refresh(), $confidential->questions()->where('key', 'comment')->first(), $investigator);
    $handle = collect($comments)->firstWhere('text', 'Concern 3')['handle'];
    expect($handle)->not->toBeNull();
    // Without the permission there are no handles at all.
    expect(collect(app(EngagementAnalytics::class)->comments($confidential, $confidential->questions()->where('key', 'comment')->first(), $this->actors['analyst']))->pluck('handle')->filter()->all())->toBe([]);

    expect(fn () => app(ConfidentialIdentities::class)->reveal('survey_response', $handle, 'short', $investigator))->toThrow(EngagementRuleViolation::class, 'reason')
        ->and(fn () => app(ConfidentialIdentities::class)->reveal('survey_response', $handle, 'Threat of self-harm reported', $this->actors['analyst']))->toThrow(EngagementRuleViolation::class);
    $who = app(ConfidentialIdentities::class)->reveal('survey_response', $handle, 'Threat of self-harm reported', $investigator);
    expect($who->id)->toBe($staff[3]->id);
    $audit = AuditEvent::query()->where('action', 'CONFIDENTIAL_RESPONSE_IDENTIFIED')->sole();
    expect($audit->actor_id)->toBe($investigator->id)->and($audit->reason)->toBe('Threat of self-harm reported')
        ->and($audit->metadata)->toBe(['subject_type' => 'survey_response', 'subject_id' => $handle])
        ->and(json_encode($audit->getAttributes()))->not->toContain((string) $staff[3]->employee_code);
});

it('reminds every invited person of an anonymous survey so notification logs reveal nothing about who responded', function () {
    submitTeam(array_slice($this->team, 0, 3), $this->version);
    $this->travelTo('2026-10-08 10:00:00');
    $sent = app(SurveyNotices::class)->remind($this->version->refresh());
    expect($sent)->toBe(13);
    $reminded = NotificationDelivery::query()->where('event', 'survey.reminder')->pluck('user_id')->unique()->sort()->values()->all();
    expect($reminded)->toBe(collect([...$this->team, ...$this->others, $this->manager, $this->otherManager])->pluck('user_id')->sort()->values()->all());
    expect(app(SurveyNotices::class)->remind($this->version->refresh()))->toBe(0);
    expect(NotificationDelivery::query()->where('event', 'survey.reminder')->pluck('body')->unique()->all())
        ->each->toContain('If you have already responded, thank you');
});
