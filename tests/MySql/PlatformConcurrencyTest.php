<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Models\ReportRun;
use App\Domain\Analytics\Models\ReportSchedule;
use App\Domain\Analytics\Services\ReportSchedules;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Enterprise\Services\Webhooks;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Models\ExternalReference;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Integration\Services\ExternalReferences;
use App\Domain\Integration\Services\InboundEvents;
use App\Domain\Notifications\Channels\Channel;
use App\Domain\Notifications\Channels\LogChannel;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Feature/Integration/IntegrationHubTestHelpers.php';
require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | Phase 14 §15: real concurrency on MySQL for the platform mechanisms added in Phase 14. Same harness
 | and opt-in as the Phase 8–13 suites (PEOPLEOS_MYSQL_CONCURRENCY_DB, name containing "concurrency");
 | SQLite runs skip these and claim nothing about MySQL locking.
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
    $this->tenant = provisionTenant('Platform race '.uniqid());
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create(['code' => 'C'.random_int(1000, 9999)]);
    $this->hire = fn (string $first) => app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Race'], ['joining_date' => '2025-01-01'], ['company_id' => $this->company->id]);
    $this->audits = fn (string $action) => AuditEvent::query()->where('tenant_id', $this->tenant->id)->where('action', $action)->count();
});

afterEach(function () {
    if (isset($this->tenant)) {
        // The audit chain of every race tenant stays intact (the platform-wide audit_chain_locks invariant).
        expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
    }
});

/** Writes slowed down so a missing claim or lock would let both writers through. */
function platformSlowEvents(): array
{
    return ['eloquent.creating: '.InboundEvent::class, 'eloquent.updating: '.InboundEvent::class, 'eloquent.creating: '.ExternalReference::class,
        'eloquent.updating: '.WebhookDelivery::class, 'eloquent.creating: '.NotificationDelivery::class, 'eloquent.updating: '.NotificationDelivery::class,
        'eloquent.creating: '.ReportRun::class, 'eloquent.creating: '.Employee::class];
}

function hubKey(): ApiKey
{
    return ApiKey::query()->latest('id')->firstOrFail();
}

it('1. records one inbound event and applies it once when the same event arrives twice at once', function () {
    $hub = hubIntegration('erp');
    $employee = ($this->hire)('Asha');
    $body = hubEvent('evt-1', 'reference.link', ['entity_type' => 'employee', 'peopleos_code' => $employee->employee_code, 'external_entity_type' => 'worker', 'external_entity_id' => 'W-1']);
    $headers = array_change_key_case(hubHeaders($hub['secret'], $body, $hub['key']), CASE_LOWER);
    $receive = fn () => app(InboundEvents::class)->receive($hub['system'], hubKey(), $body, $headers);

    expect(race([$receive, $receive], slow: platformSlowEvents()))->toBe(['ok', 'ok'])
        ->and(InboundEvent::query()->where('external_event_id', 'evt-1')->count())->toBe(1)
        ->and(ExternalReference::query()->where('external_entity_id', 'W-1')->count())->toBe(1)
        ->and(($this->audits)('INTEGRATION_EVENT_RECEIVED'))->toBe(1)
        ->and(($this->audits)('INTEGRATION_EVENT_PROCESSED'))->toBe(1);
});

it('2. applies an inbound event once when two workers process it at once (leased claim)', function () {
    $hub = hubIntegration('hris');
    $employee = ($this->hire)('Bala');
    $body = hubEvent('evt-2', 'reference.link', ['entity_type' => 'employee', 'peopleos_code' => $employee->employee_code, 'external_entity_type' => 'worker', 'external_entity_id' => 'W-2']);
    Queue::fake();
    $event = app(InboundEvents::class)->receive($hub['system'], hubKey(), $body, array_change_key_case(hubHeaders($hub['secret'], $body, $hub['key']), CASE_LOWER))['event'];
    $process = fn () => app(InboundEvents::class)->process(InboundEvent::query()->findOrFail($event->id));

    expect(race([$process, $process], slow: platformSlowEvents()))->toBe(['ok', 'ok'])
        ->and($event->refresh()->status)->toBe('succeeded')->and($event->attempts)->toBe(1)
        ->and(ExternalReference::query()->where('external_entity_id', 'W-2')->count())->toBe(1)
        ->and(($this->audits)('INTEGRATION_EVENT_PROCESSED'))->toBe(1);
});

it('3. keeps one owner for an external id when two links race for it', function () {
    $system = hubIntegration('payroll')['system'];
    [$a, $b] = [($this->hire)('Chitra'), ($this->hire)('Dev')];
    $link = fn (Employee $e) => fn () => app(ExternalReferences::class)->link($system, Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($e->id), 'worker', 'W-3');
    $results = race([$link($a), $link($b)], slow: platformSlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(ExternalReference::query()->where('external_entity_id', 'W-3')->count())->toBe(1)
        ->and(($this->audits)('EXTERNAL_REFERENCE_LINKED'))->toBe(1);
});

it('4. sends a webhook delivery once when two workers pick it up at once', function () {
    // Every real send appends one byte to a file shared by the forked workers.
    $sends = tempnam(sys_get_temp_dir(), 'peopleos-webhook');
    Http::fake(function () use ($sends) {
        file_put_contents($sends, 'x', FILE_APPEND | LOCK_EX);

        return Http::response('ok', 200);
    });
    WebhookEndpoint::create(['name' => 'Sink', 'url' => 'https://sink.example.test/hook', 'secret' => 's', 'events' => ['*']]);
    app(Webhooks::class)->publish('employee.updated', ['code' => 'X']);
    $delivery = WebhookDelivery::query()->latest('id')->firstOrFail();
    $deliver = fn () => app(Webhooks::class)->deliver($delivery->id);

    expect(race([$deliver, $deliver], slow: platformSlowEvents()))->toBe(['ok', 'ok'])
        ->and($delivery->refresh()->status)->toBe('delivered')->and($delivery->attempts)->toBe(1)
        ->and(strlen((string) file_get_contents($sends)))->toBe(1);
    unlink($sends);
});

it('5. creates one employee for two identical API requests with the same Idempotency-Key', function () {
    $key = app(ApiKeys::class)->issue('Writer', ['employees.write', 'employees.read'])['plaintext'];
    $payload = ['person' => ['first_name' => 'Idem', 'last_name' => 'Potent'], 'employee' => ['joining_date' => '2026-01-01'], 'position' => ['company_code' => $this->company->code]];
    $post = fn () => tap($this->withHeaders(['X-Api-Key' => $key, 'Idempotency-Key' => 'race-key-0005'])->postJson('/api/v1/employees', $payload),
        fn ($r) => in_array($r->status(), [201, 409], true) || throw new RuntimeException('status '.$r->status().' '.$r->content()));
    $results = race([$post, $post], slow: platformSlowEvents());

    expect($results)->toBe(['ok', 'ok'])
        ->and(Employee::query()->withoutGlobalScope(AccessScope::class)->whereHas('person', fn ($q) => $q->where('first_name', 'Idem'))->count())->toBe(1);
});

it('6. records one notification per channel when the same message is sent twice in one operation', function () {
    $user = tenantUser($this->tenant, ['task.view']);
    Context::add('request_id', 'race-corr-0006');
    $send = fn () => app(Notifier::class)->send([$user], ['in_app', 'log'], 'Same', 'Message', 'race.event');
    config(['peopleos.notifications.channels.log' => ['label' => 'Log', 'driver' => LogChannel::class]]);
    $results = race([$send, $send], slow: platformSlowEvents());
    Context::forget('request_id');

    expect($results)->toBe(['ok', 'ok'])
        ->and(NotificationDelivery::query()->where('event', 'race.event')->where('channel', 'in_app')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('event', 'race.event')->where('channel', 'log')->count())->toBe(1)
        ->and($user->notifications()->count())->toBe(1);
});

it('7. sends a queued notification once when two delivery jobs claim it at once', function () {
    $file = tempnam(sys_get_temp_dir(), 'peopleos-sends');
    config(['peopleos.notifications.channels.counting' => ['label' => 'Counting', 'driver' => CountingRaceChannel::class], 'peopleos.notifications.race_file' => $file]);
    $user = tenantUser($this->tenant, ['task.view']);
    $delivery = NotificationDelivery::create(['user_id' => $user->id, 'channel' => 'counting', 'event' => 'race.claim', 'subject' => 'S', 'body' => 'B', 'status' => 'queued']);
    $deliver = fn () => app(Notifier::class)->deliver(NotificationDelivery::query()->findOrFail($delivery->id));
    $results = race([$deliver, $deliver], slow: platformSlowEvents());

    expect($results)->toBe(['ok', 'ok'])->and(strlen((string) file_get_contents($file)))->toBe(1)->and($delivery->refresh()->status)->toBe('sent');
    unlink($file);
});

it('8. grants a once-per-day scheduler slot to exactly one of two concurrent runs', function () {
    $claim = fn () => TenantRunner::claim('race.sweep', '2026-10-05') ?: throw new RuntimeException('not claimed');
    $results = race([$claim, $claim]);

    expect(collect($results)->sort()->values()->all())->toBe(['not claimed', 'ok'])
        ->and(DB::table('scheduler_claims')->where('tenant_id', $this->tenant->id)->where('key', 'race.sweep')->count())->toBe(1);
});

it('9. exports a due report schedule once when two scheduler runs overlap', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    ($this->hire)('Esha');
    $report = Report::create(['name' => 'People', 'dataset' => 'employees', 'definition' => ['fields' => ['employee_code']], 'owner_id' => $this->hr->id]);
    $schedule = ReportSchedule::create(['report_id' => $report->id, 'frequency' => 'daily', 'time' => '07:00', 'recipient_user_ids' => []]);
    $this->travelTo('2026-10-06 07:30:00');
    $run = fn () => app(ReportSchedules::class)->runDue();

    expect(race([$run, $run], slow: platformSlowEvents()))->toBe(['ok', 'ok'])
        ->and(ReportRun::query()->where('report_schedule_id', $schedule->id)->count())->toBe(1);
});

it('10. gives two concurrent hires distinct employee codes and one person each', function () {
    $hire = fn (string $first) => fn () => app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Same'], ['joining_date' => '2025-01-01'], ['company_id' => $this->company->id]);
    $before = Employee::query()->withoutGlobalScope(AccessScope::class)->count();

    expect(race([$hire('Farah'), $hire('Gita')], slow: platformSlowEvents()))->toBe(['ok', 'ok']);
    $codes = Employee::query()->withoutGlobalScope(AccessScope::class)->latest('id')->limit(2)->pluck('employee_code');
    expect(Employee::query()->withoutGlobalScope(AccessScope::class)->count())->toBe($before + 2)->and($codes->unique())->toHaveCount(2);
});

/** Counts real sends across forked processes (one byte per send). */
final class CountingRaceChannel implements Channel
{
    public function send(NotificationDelivery $delivery): void
    {
        file_put_contents((string) config('peopleos.notifications.race_file'), 'x', FILE_APPEND | LOCK_EX);
    }
}
