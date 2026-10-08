<?php

use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Succession\Services\CriticalPositions;
use App\Domain\Succession\Services\Readiness;
use App\Domain\Succession\Services\SuccessionPlans;
use App\Domain\Talent\Jobs\SendTalentReminders;
use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentReminderLog;
use App\Domain\Talent\Services\TalentPools;
use App\Domain\Talent\Services\TalentReminders;
use App\Domain\Talent\Services\TalentReviews;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->hr = activeEmployee(null, ['succession.view', 'succession.manage', 'succession.assess', 'talent.view', 'talent.manage', 'talent.review']);
    $this->candidate = activeEmployee(null, ['career.self']);
    $this->assessment = ['criticality' => 'high', 'business_impact' => 'high', 'scarcity' => 'high', 'replacement_difficulty' => 'high', 'operational_dependency' => 'high', 'reason' => 'Key role'];
    $this->position = app(CriticalPositions::class)->designate(Designation::query()->create(['name' => 'COO', 'code' => 'COO']), null, 'Chief Operating Officer', $this->assessment, 1, $this->hr->user);
});

it('reminds record owners about reviews and expiring readiness, throttled, and never tells the employee', function () {
    $plan = app(SuccessionPlans::class)->create($this->position, ['review_date' => '2026-10-15'], $this->hr->user);
    $successor = app(SuccessionPlans::class)->addSuccessor($plan, $this->candidate, null, null, null, $this->hr->user);
    config(['peopleos.talent.readiness_validity_months' => 1]);
    app(Readiness::class)->assess($this->candidate, $this->position->id, null, 'lt_1_year', 'Rotation pending', null, $this->hr->user, $successor);
    app(TalentReviews::class)->create('Q4 review', null, [$this->hr->user->id], $this->admin, '2026-10-12');

    $this->travelTo('2026-10-25 09:00:00');
    $first = app(TalentReminders::class)->tick();
    $again = app(TalentReminders::class)->tick();

    expect($first)->toBe(['position_reviews' => 1, 'plan_reviews' => 1, 'readiness_expiring' => 1, 'talent_reviews' => 0])
        ->and($again)->toBe(['position_reviews' => 0, 'plan_reviews' => 0, 'readiness_expiring' => 0, 'talent_reviews' => 0])
        ->and(NotificationDelivery::query()->where('user_id', $this->hr->user->id)->where('event', 'like', 'succession.reminder.%')->count())->toBe(3)
        ->and(NotificationDelivery::query()->where('user_id', $this->candidate->user->id)->count())->toBe(0);

    $this->travelTo('2026-11-02 09:00:00'); // a week on, overdue reviews may be reminded again; readiness only once
    expect(app(TalentReminders::class)->tick())->toMatchArray(['position_reviews' => 1, 'plan_reviews' => 1, 'readiness_expiring' => 0])
        ->and(TalentReminderLog::query()->count())->toBe(5);
});

it('reminds review participants and runs as a tenant-bound queued job', function () {
    app(TalentReviews::class)->create('Q4 review', null, [$this->hr->user->id], $this->admin, '2026-10-12');
    expect(app(TalentReminders::class)->tick()['talent_reviews'])->toBe(1)
        ->and(NotificationDelivery::query()->where('user_id', $this->hr->user->id)->where('event', 'talent.reminder.review_scheduled')->exists())->toBeTrue();

    Queue::fake();
    $this->artisan('peopleos:talent:send-reminders', ['--queue' => true])->assertSuccessful();
    Queue::assertPushed(SendTalentReminders::class, fn ($job) => $job->tenantId() === $this->tenant->id);
    $this->artisan('peopleos:talent:send-reminders')->assertSuccessful();
});

it('never notifies the candidate or publishes confidential talent events as webhooks', function () {
    Http::fake();
    WebhookEndpoint::create(['name' => 'All', 'url' => 'https://all.example.test/hooks', 'secret' => 's', 'events' => ['*']]);
    $position = app(CriticalPositions::class)->designate(Designation::query()->create(['name' => 'CFO', 'code' => 'CFO']), null, 'Chief Financial Officer', $this->assessment, 12, $this->hr->user);
    $plan = app(SuccessionPlans::class)->create($position, [], $this->hr->user);
    app(SuccessionPlans::class)->addSuccessor($plan, $this->candidate, null, null, null, $this->hr->user);
    app(TalentPools::class)->add(TalentPool::create(['code' => 'HIPO', 'name' => 'Leaders']), $this->candidate, 'Strong', $this->hr->user);

    $events = WebhookDelivery::query()->pluck('event')->unique()->values()->all();
    expect($events)->toContain('succession.critical_position.created')
        ->and($events)->not->toContain('succession.successor.added', 'succession.plan.created', 'talent.pool.membership_changed', 'succession.readiness.assessed')
        ->and(NotificationDelivery::query()->where('user_id', $this->candidate->user->id)->count())->toBe(0)
        ->and(NotificationDelivery::query()->where('user_id', $this->hr->user->id)->where('event', 'succession.successor.added')->exists())->toBeTrue();
});
