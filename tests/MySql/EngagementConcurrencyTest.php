<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Communication\Models\CommunicationPreference;
use App\Domain\Communication\Models\CommunicationRecipient;
use App\Domain\Communication\Services\CommunicationDelivery;
use App\Domain\Communication\Services\CommunicationPreferences;
use App\Domain\Communication\Services\Communications;
use App\Domain\Engagement\Models\EngagementCampaign;
use App\Domain\Engagement\Models\EngagementIdentity;
use App\Domain\Engagement\Models\EngagementReminderLog;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyResponse;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Engagement\Services\Campaigns;
use App\Domain\Engagement\Services\SurveyNotices;
use App\Domain\Engagement\Services\SurveyResponses;
use App\Domain\Engagement\Services\Surveys;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Feature/Engagement/EngagementTestHelpers.php';
require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | Phase 13 §52: real concurrency on MySQL for engagement and communication. Same harness and opt-in as
 | the Phase 8–12 suites (PEOPLEOS_MYSQL_CONCURRENCY_DB, name containing "concurrency"); SQLite runs
 | skip these and claim nothing about MySQL locking.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency', 'queue.default' => 'sync']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant('Engagement race '.uniqid());
    actAsTenant($this->tenant);
    $this->actors = engagementActors();
    $this->staff = engagementTeam(6);
    $this->audits = fn (string $action, ?int $entityId = null) => AuditEvent::query()->where('tenant_id', $this->tenant->id)->where('action', $action)
        ->when($entityId, fn ($q) => $q->where('entity_id', (string) $entityId))->count();
});

/** Engagement and communication writes slowed down, so a missing lock would let both writers through. */
function engagementSlowEvents(): array
{
    return ['eloquent.updating: '.SurveyParticipation::class, 'eloquent.creating: '.SurveyResponse::class, 'eloquent.updating: '.SurveyVersion::class,
        'eloquent.updating: '.EngagementCampaign::class, 'eloquent.updating: '.CommunicationRecipient::class, 'eloquent.updating: '.AnnouncementRead::class,
        'eloquent.updating: '.Announcement::class];
}

function participations(int $versionId)
{
    return SurveyParticipation::query()->withoutGlobalScope(AccessScope::class)->where('survey_version_id', $versionId);
}

it('1. records one response when an identified respondent submits twice at once', function () {
    $version = openEngagementSurvey($this->actors, ['anonymity_mode' => 'identified']);
    $user = $this->staff[0]->user;
    $results = race([fn () => app(SurveyResponses::class)->submit($version, $user, ['mood' => '4'], 'first'), fn () => app(SurveyResponses::class)->submit($version, $user, ['mood' => '2'], 'second')], slow: engagementSlowEvents());

    expect($results)->toBe(['ok', 'ok'])
        ->and(SurveyResponse::query()->where('survey_version_id', $version->id)->count())->toBe(1)
        ->and(participations($version->id)->where('status', 'submitted')->count())->toBe(1)
        ->and(($this->audits)('SURVEY_RESPONSE_SUBMITTED', $version->id))->toBe(1);
});

it('2. records one response for two submissions with the same idempotency key', function () {
    $version = openEngagementSurvey($this->actors, ['anonymity_mode' => 'identified', 'response_rule' => 'multiple']);
    $submit = fn () => app(SurveyResponses::class)->submit($version, $this->staff[0]->user, ['mood' => '4'], 'same-key');
    $results = race([$submit, $submit], slow: engagementSlowEvents());

    expect($results)->toBe(['ok', 'ok'])
        ->and(SurveyResponse::query()->where('survey_version_id', $version->id)->where('idempotency_key', 'same-key')->count())->toBe(1)
        ->and(($this->audits)('SURVEY_RESPONSE_SUBMITTED', $version->id))->toBe(1);
});

it('3. keeps closure and submission consistent: a response exists exactly when its participation is submitted', function () {
    $version = openEngagementSurvey($this->actors);
    $results = race([fn () => app(Surveys::class)->close(SurveyVersion::query()->findOrFail($version->id)), fn () => app(SurveyResponses::class)->submit($version, $this->staff[0]->user, ['mood' => '3'])], slow: engagementSlowEvents());

    $responses = SurveyResponse::query()->where('survey_version_id', $version->id)->count();
    expect($results[0])->toBe('ok')->and($results[1])->toBeIn(['ok', 'This survey is not open.'])
        ->and(SurveyVersion::query()->find($version->id)->status)->toBe('closed')
        ->and($responses)->toBe(participations($version->id)->where('status', 'submitted')->count())
        ->and($responses)->toBe($results[1] === 'ok' ? 1 : 0)
        ->and(participations($version->id)->where('status', 'expired')->count())->toBe(6 - $responses)
        ->and(($this->audits)('SURVEY_CLOSED', $version->id))->toBe(1);
});

it('4. launches a campaign once when two launches race', function () {
    $comms = app(Communications::class);
    $campaigns = app(Campaigns::class);
    $announcement = $comms->approve($comms->submit($comms->create(['title' => 'Race launch', 'body' => 'x'], $this->actors['preparer']), $this->actors['preparer']), null, $this->actors['approver']);
    $campaign = $campaigns->create(['code' => 'RACE', 'name' => 'Race', 'starts_on' => '2026-10-10'], $this->actors['preparer']);
    $campaigns->addItem($campaign, 'announcement', $announcement->id, $this->actors['preparer']);
    $campaign = $campaigns->schedule($campaigns->approve($campaigns->submit($campaign, $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
    $this->travelTo('2026-10-10 08:00:00');
    $launch = fn () => app(Campaigns::class)->launch(EngagementCampaign::query()->findOrFail($campaign->id));
    $results = race([$launch, $launch], slow: engagementSlowEvents());

    expect($results)->toBe(['ok', 'ok'])
        ->and(($this->audits)('CAMPAIGN_PUBLISHED', $campaign->id))->toBe(1)
        ->and(($this->audits)('ANNOUNCEMENT_PUBLISHED', $announcement->id))->toBe(1)
        ->and(CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $announcement->id)->count())->toBe(6)
        ->and(EngagementCampaign::query()->find($campaign->id)->status)->toBe('active');
});

it('5. sends each reminder once when two reminder runs overlap', function () {
    $version = openEngagementSurvey($this->actors);
    $this->travelTo('2026-10-08 10:00:00');
    $remind = fn () => app(SurveyNotices::class)->remind(SurveyVersion::query()->findOrFail($version->id));
    $results = race([$remind, $remind], slow: ['eloquent.creating: '.NotificationDelivery::class]);

    $perUser = NotificationDelivery::query()->where('event', 'survey.reminder')->select('user_id', 'channel', DB::raw('count(*) as n'))->groupBy('user_id', 'channel')->pluck('n')->unique()->values()->all();
    expect($results)->toBe(['ok', 'ok'])->and($perUser)->toBe([1])
        ->and(EngagementReminderLog::query()->where('reminder', 'survey.reminder')->count())->toBe(6)
        ->and(participations($version->id)->pluck('reminders_sent')->unique()->values()->all())->toBe([1]);
});

it('6. notifies each recipient once when two delivery jobs run together', function () {
    config(['queue.default' => 'null']);
    $comms = app(Communications::class);
    $announcement = $comms->publish($comms->approve($comms->submit($comms->create(['title' => 'Delivery race', 'body' => 'x'], $this->actors['preparer']), $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
    $deliver = fn () => app(CommunicationDelivery::class)->deliver(Announcement::query()->findOrFail($announcement->id));
    $results = race([$deliver, $deliver], slow: engagementSlowEvents());

    $perUser = NotificationDelivery::query()->where('source_id', $announcement->id)->where('event', 'communication.published')
        ->select('user_id', 'channel', DB::raw('count(*) as n'))->groupBy('user_id', 'channel')->pluck('n')->unique()->values()->all();
    $recipients = CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $announcement->id)->get();
    expect($results)->toBe(['ok', 'ok'])->and($perUser)->toBe([1])
        ->and($recipients->pluck('status')->unique()->values()->all())->toBe(['sent'])
        ->and($recipients->pluck('attempts')->unique()->values()->all())->toBe([1]);
});

it('7. records one acknowledgement per person when acknowledgements race, whether or not the item was read before', function () {
    $comms = app(Communications::class);
    $announcement = $comms->publish($comms->approve($comms->submit($comms->create(['title' => 'Ack race', 'body' => 'x', 'requires_acknowledgement' => true], $this->actors['preparer']), $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
    $comms->markRead($announcement, $this->staff[1]); // the read row already exists for this person
    $ack = fn ($employee) => fn () => app(Communications::class)->acknowledge(Announcement::query()->findOrFail($announcement->id), $employee);
    $results = race([$ack($this->staff[0]), $ack($this->staff[0]), $ack($this->staff[1]), $ack($this->staff[1])], slow: engagementSlowEvents());

    $reads = AnnouncementRead::query()->where('announcement_id', $announcement->id)->get()->groupBy('employee_id');
    expect($results)->toBe(['ok', 'ok', 'ok', 'ok'])
        ->and($reads[$this->staff[0]->id])->toHaveCount(1)->and($reads[$this->staff[1]->id])->toHaveCount(1)
        ->and($reads->flatten()->pluck('acknowledged_at')->filter())->toHaveCount(2)
        ->and(($this->audits)('ANNOUNCEMENT_ACKNOWLEDGED', $announcement->id))->toBe(2);
});

it('8. takes one audience snapshot when two openings (and two releases) race', function () {
    $surveys = app(Surveys::class);
    $version = $surveys->create(['code' => 'SNAP', 'name' => 'Snapshot race', 'opens_at' => '2026-10-06 09:00:00', 'closes_at' => '2026-10-20 18:00:00', 'audience_criteria' => ['lifecycle_states' => ['active']]], $this->actors['preparer'])->versions()->first();
    $surveys->saveQuestion($version, ['key' => 'q', 'type' => 'yes_no', 'prompt' => 'Q?'], $this->actors['preparer']);
    $version = $surveys->publish($surveys->approve($surveys->submit($version->refresh(), $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
    $comms = app(Communications::class);
    $announcement = $comms->publish($comms->approve($comms->submit($comms->create(['title' => 'Release race', 'body' => 'x', 'publish_at' => '2026-10-06 09:00:00'], $this->actors['preparer']), $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
    $this->travelTo('2026-10-06 09:30:00');
    $open = fn () => app(Surveys::class)->open(SurveyVersion::query()->findOrFail($version->id));
    $release = fn () => app(Communications::class)->release(Announcement::query()->findOrFail($announcement->id));
    $results = race([$open, $open, $release, $release], slow: engagementSlowEvents());

    expect($results)->toBe(['ok', 'ok', 'ok', 'ok'])
        ->and(participations($version->id)->count())->toBe(6)
        ->and(($this->audits)('SURVEY_OPENED', $version->id))->toBe(1)->and(($this->audits)('AUDIENCE_USED', $version->id))->toBe(1)
        ->and(CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $announcement->id)->count())->toBe(6)
        ->and(($this->audits)('ANNOUNCEMENT_PUBLISHED', $announcement->id))->toBe(1);
});

it('9. keeps anonymous responses unlinked and single under concurrent submissions', function () {
    $version = openEngagementSurvey($this->actors);
    // Five people at once, then one person (who has not answered yet) twice at once.
    $results = race(array_map(fn ($e) => fn () => app(SurveyResponses::class)->submit($version, $e->user, ['mood' => '3']), array_slice($this->staff, 1)), slow: engagementSlowEvents());
    $twice = race([fn () => app(SurveyResponses::class)->submit($version, $this->staff[0]->user, ['mood' => '1']), fn () => app(SurveyResponses::class)->submit($version, $this->staff[0]->user, ['mood' => '5'])], slow: engagementSlowEvents());

    $responses = SurveyResponse::query()->where('survey_version_id', $version->id)->get();
    $audits = AuditEvent::query()->where('tenant_id', $this->tenant->id)->where('action', 'SURVEY_RESPONSE_SUBMITTED')->get();
    expect($results)->toBe(array_fill(0, 5, 'ok'))->and($twice)->toBe(['ok', 'ok'])
        ->and($responses)->toHaveCount(6)
        ->and($responses->pluck('employee_id')->filter()->all())->toBe([])->and($responses->pluck('submitted_on')->filter()->all())->toBe([])
        ->and($responses->pluck('id')->map(fn ($id) => $id[14])->unique()->values()->all())->toBe(['4'])
        ->and(participations($version->id)->where('status', 'submitted')->count())->toBe(6)
        ->and($audits)->toHaveCount(6)->and($audits->pluck('actor_id')->filter()->all())->toBe([])->and($audits->pluck('ip_address')->filter()->all())->toBe([])
        ->and(EngagementIdentity::query()->count())->toBe(0);
});

it('10. honours a preference change that commits while a delivery is claiming the recipient', function () {
    config(['queue.default' => 'null']);
    $employee = $this->staff[0];
    $prefs = app(CommunicationPreferences::class);
    $prefs->set($employee, 'newsletter', true, true, $employee->user); // the row exists: the change locks it
    $comms = app(Communications::class);
    $announcement = $comms->publish($comms->approve($comms->submit($comms->create(['title' => 'Newsletter race', 'type' => 'newsletter', 'body' => 'x'], $this->actors['preparer']), $this->actors['preparer']), null, $this->actors['approver']), $this->actors['preparer']);
    $recipient = CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->where('announcement_id', $announcement->id)->where('employee_id', $employee->id)->firstOrFail();

    // The opt-out starts first and holds its row lock; the delivery claims the recipient meanwhile.
    $results = race([
        fn () => app(CommunicationPreferences::class)->set($employee, 'newsletter', true, false, $employee->user),
        function () use ($announcement, $recipient) {
            usleep(150_000);
            app(CommunicationDelivery::class)->deliverOne(Announcement::query()->findOrFail($announcement->id), $recipient->id);
        },
    ], pause: 0.8, slow: ['eloquent.updating: '.CommunicationPreference::class]);

    $final = CommunicationRecipient::query()->withoutGlobalScope(AccessScope::class)->findOrFail($recipient->id);
    expect($results)->toBe(['ok', 'ok'])->and($final->status)->toBe('sent')->and($final->channels)->toBe(['in_app'])
        ->and(NotificationDelivery::query()->where('source_id', $announcement->id)->where('user_id', $employee->user_id)->pluck('channel')->all())->toBe(['in_app'])
        ->and(CommunicationPreference::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->value('email'))->toBeFalse();
});
