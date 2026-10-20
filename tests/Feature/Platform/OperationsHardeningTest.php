<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Models\ReportRun;
use App\Domain\Analytics\Services\ReportExports;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Support\Observability\RedactSensitiveLogData;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Phase 14.6 operations hardening: health (live / ready), log redaction, queue job rules and tenant
 * binding, notifications (true in-app sent, async email after commit, dedupe, masked templates),
 * scheduler isolation and claims, and storage (no framework file serving, tenant-prefixed and
 * fingerprinted grievance evidence, audited and owner-only report re-downloads).
 */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
});

function scratchCommand(): Command
{
    $command = new class extends Command
    {
        protected $signature = 'peopleos:test-runner';
    };
    $command->setLaravel(app());
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return $command;
}

it('answers liveness without dependencies and readiness with critical and warning checks, never leaking detail without the token', function () {
    $this->get('/health/live')->assertOk()->assertJson(['status' => 'live']);

    Storage::fake('local');
    $ready = $this->get('/health/ready')->assertOk()->json();
    expect($ready['status'])->toBeIn(['ok', 'degraded'])
        ->and(array_keys($ready['checks']))->toContain('database', 'cache', 'storage', 'queue', 'failed_jobs', 'scheduler', 'integrations')
        ->and($ready['checks']['database'])->toBe(['status' => 'ok', 'critical' => true])
        ->and(json_encode($ready))->not->toContain($this->tenant->slug)->not->toContain('detail');

    Artisan::call('peopleos:scheduler:heartbeat');
    config(['peopleos.health.token' => 'probe-token-123']);
    $detailed = $this->withHeaders(['X-Health-Token' => 'probe-token-123'])->get('/health/ready')->assertOk()->json();
    expect($detailed['checks']['scheduler']['status'])->toBe('ok')->and($detailed['checks']['queue']['detail'])->toHaveKey('pending');
    $this->flushHeaders();

    // A critical dependency down → 503 "down".
    config(['peopleos.documents.disk' => 'no-such-disk']);
    $this->get('/health/ready')->assertStatus(503)->assertJsonPath('status', 'down')->assertJsonPath('checks.storage.status', 'fail');
});

it('redacts secrets, identifiers and amounts from every log channel and stamps the tenant', function () {
    foreach (['single', 'daily', 'stderr'] as $channel) {
        expect(config("logging.channels.{$channel}.tap"))->toContain(RedactSensitiveLogData::class);
    }
    $handler = new TestHandler;
    $monolog = new Logger('test', [$handler]);
    (new RedactSensitiveLogData)(new Illuminate\Log\Logger($monolog));

    $monolog->info('Paid using Bearer abcdefghijklmnop for PAN ABCDE1234F', ['password' => 'hunter2', 'employee' => ['salary' => 120000, 'name' => 'Asha', 'account_number' => '9988776655'], 'ref' => 'acct 123456789012', 'api_key' => 'pk_live_abcdef123456']);
    $record = $handler->getRecords()[0];
    $text = $record->message.json_encode($record->context).json_encode($record->extra);
    expect($text)->not->toContain('hunter2')->not->toContain('ABCDE1234F')->not->toContain('abcdefghijklmnop')->not->toContain('120000')
        ->not->toContain('9988776655')->not->toContain('123456789012')->not->toContain('pk_live')
        ->toContain('Asha')->and($record->extra['tenant_id'])->toBe($this->tenant->id);
});

it('keeps every queued job bounded: tries, timeout below retry_after, backoff when retried', function () {
    $retryAfter = collect(['database', 'redis', 'beanstalkd'])->map(fn ($c) => (int) config("queue.connections.{$c}.retry_after"))->min();
    $jobs = collect(explode("\n", trim(shell_exec('grep -rl "implements.*ShouldQueue" '.escapeshellarg(app_path())))))
        ->map(fn ($file) => 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path()) + 1)));
    expect($jobs->count())->toBeGreaterThan(15);
    foreach ($jobs as $class) {
        $defaults = (new ReflectionClass($class))->getDefaultProperties();
        expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue()
            ->and($defaults['tries'] ?? null)->toBeInt("{$class} declares tries")
            ->and($defaults['timeout'] ?? null)->toBeInt("{$class} declares timeout")
            ->and($defaults['timeout'])->toBeLessThan($retryAfter);
        if ($defaults['tries'] > 1) {
            expect(($defaults['backoff'] ?? null) !== null || method_exists($class, 'backoff'))->toBeTrue("{$class} backs off");
        }
    }
});

it('refuses tenant-aware jobs without a tenant, skips suspended tenants, and binds the tenant otherwise', function () {
    $job = fn (?int $tenantId) => new class($tenantId) implements TenantAwareJob
    {
        public function __construct(private ?int $id) {}

        public function tenantId(): ?int
        {
            return $this->id;
        }
    };
    $middleware = new BindTenantContext;

    expect(fn () => $middleware->handle($job(null), fn () => 'ran'))->toThrow(RuntimeException::class, 'without a tenant');
    $seen = null;
    expect($middleware->handle($job($this->tenant->id), function () use (&$seen) {
        $seen = app(TenantContext::class)->id();

        return 'ran';
    }))->toBe('ran')->and($seen)->toBe($this->tenant->id);

    $suspended = provisionTenant('Dormant');
    $suspended->forceFill(['status' => TenantStatus::Suspended])->save();
    $ran = false;
    $middleware->handle($job($suspended->id), function () use (&$ran) {
        $ran = true;
    });
    expect($ran)->toBeFalse();
});

it('stores in-app notices at once, sends email after commit through a claimed job, and dedupes within one operation', function () {
    Queue::fake();
    Mail::fake();
    $user = tenantUser($this->tenant, ['task.view']);
    Context::add('request_id', 'corr-ops-0001');

    $deliveries = app(Notifier::class)->send([$user], ['in_app', 'email'], 'Subject', 'Body', 'ops.event');
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->firstWhere('channel', 'in_app')->refresh()->status)->toBe('sent')
        ->and($user->notifications()->count())->toBe(1)
        ->and($deliveries->firstWhere('channel', 'email')->refresh()->status)->toBe('queued')
        ->and($deliveries->every(fn ($d) => $d->correlation_id === 'corr-ops-0001'))->toBeTrue();
    Queue::assertPushed(DeliverNotification::class, 1);

    // The same message again in the same operation (e.g. a retried job) is not recorded twice.
    expect(app(Notifier::class)->send([$user], ['in_app', 'email'], 'Subject', 'Body', 'ops.event'))->toHaveCount(0);

    $email = $deliveries->firstWhere('channel', 'email');
    (new DeliverNotification($email->id))->handle(app(Notifier::class));
    (new DeliverNotification($email->id))->handle(app(Notifier::class));
    expect($email->refresh()->status)->toBe('sent');
    Mail::assertSentCount(1);

    Context::add('request_id', 'corr-ops-0002');
    expect(app(Notifier::class)->send([$user], ['in_app'], 'Subject', 'Body', 'ops.event'))->toHaveCount(1);
    Context::forget('request_id');
});

it('never exposes a classified record\'s values to notification templates', function () {
    $company = Company::factory()->create();
    $employee = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01'], ['company_id' => $company->id]);
    $bank = EmployeeBankAccount::create(['employee_id' => $employee->id, 'account_holder_name' => 'Asha', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);

    $subject = app(NotificationContext::class)->build($bank)['subject'];
    expect($subject)->toHaveKeys(['type', 'id'])->not->toHaveKey('account_number')->not->toHaveKey('ifsc')->not->toHaveKey('bank_name')
        ->and(json_encode($subject))->not->toContain('1234567890');
    expect(app(NotificationContext::class)->build($employee)['subject'])->toHaveKey('employee_code');
});

it('isolates each tenant in scheduled runs, skips suspended tenants, and claims once-per-day sweeps', function () {
    $second = provisionTenant('Second');
    $dormant = provisionTenant('Dormant');
    $dormant->forceFill(['status' => TenantStatus::Suspended])->save();
    $runner = TenantRunner::for(scratchCommand());
    $ran = [];
    Tenant::query()->orderBy('id')->each($runner->isolate(function ($tenant) use (&$ran) {
        if ($tenant->id === $this->tenant->id) {
            throw new RuntimeException('boom');
        }
        $ran[] = $tenant->id;
    }));
    expect($runner->failed())->toBe(1)->and($runner->exitCode())->toBe(Command::FAILURE)
        ->and($ran)->toContain($second->id)->not->toContain($dormant->id);

    actAsTenant($this->tenant);
    expect(Artisan::call('peopleos:lifecycle:reminders', ['--tenant' => $this->tenant->slug]))->toBe(0);
    Artisan::call('peopleos:lifecycle:reminders', ['--tenant' => $this->tenant->slug]);
    expect(Artisan::output())->toContain('already swept today');
    Artisan::call('peopleos:lifecycle:reminders', ['--tenant' => $this->tenant->slug, '--force' => true]);
    expect(Artisan::output())->not->toContain('already swept today');

    // Every tenant-iterating scheduled command goes through the runner; every entry is overlap- and server-guarded.
    $events = app(Schedule::class)->events();
    expect($events)->not->toBeEmpty();
    $inventory = file_get_contents(base_path('docs/operations/queue-and-scheduler.md'));
    foreach ($events as $event) {
        preg_match('/(peopleos:[a-z:-]+)/', $event->command, $m);
        expect($event->withoutOverlapping)->toBeTrue("{$m[1]} withoutOverlapping")->and($event->onOneServer)->toBeTrue("{$m[1]} onOneServer")
            ->and($inventory)->toContain('`'.$m[1].'`')
            ->and(array_key_exists($m[1], Artisan::all()))->toBeTrue();
        $file = collect(glob(app_path('Console/Commands/*.php')))->first(fn ($f) => str_contains(file_get_contents($f), "signature = '{$m[1]}"));
        if (str_contains(file_get_contents($file), 'Tenant::query()')) {
            expect(file_get_contents($file))->toContain('TenantRunner::for($this)');
        }
    }
});

it('serves private files only through audited routes, fingerprints grievance evidence and checks it on download', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
    Storage::fake('local');
    $company = Company::factory()->create();
    $employee = app(HireEmployeeAction::class)->handle(['first_name' => 'Ravi', 'last_name' => 'K'], ['joining_date' => '2025-01-01'], ['company_id' => $company->id]);
    $case = Grievance::create(['number' => 'GRV-OPS-1', 'grievance_category_id' => GrievanceCategory::query()->value('id'), 'employee_id' => $employee->id, 'subject' => 'x', 'details' => 'y', 'status' => 'under_review']);
    $grievances = app(Grievances::class);

    Storage::disk('local')->put('grievances/upload.pdf', 'evidence-bytes');
    $note = $grievances->addNote($case, $this->hr, 'note', 'Evidence attached', false, 'grievances/upload.pdf', 'statement.pdf');
    expect($note->attachment_path)->toStartWith("tenants/{$this->tenant->id}/grievances/{$case->id}/")
        ->and($note->attachment_sha256)->toBe(hash('sha256', 'evidence-bytes'))->and($note->attachment_name)->toBe('statement.pdf');
    Storage::disk('local')->assertMissing('grievances/upload.pdf');

    Storage::disk('local')->put('grievances/run.exe', 'x');
    expect(fn () => $grievances->addNote($case, $this->hr, 'note', 'bad', false, 'grievances/run.exe', 'run.exe'))->toThrow(RuntimeException::class, 'not accepted');
    Storage::disk('local')->put('tenants/999/other.pdf', 'x');
    expect(fn () => $grievances->addNote($case, $this->hr, 'note', 'bad', false, 'tenants/999/other.pdf', 'o.pdf'))->toThrow(RuntimeException::class, 'not found');

    $this->get($grievances->attachmentUrl($note))->assertOk();
    Storage::disk('local')->put($note->attachment_path, 'tampered');
    $this->get($grievances->attachmentUrl($note))->assertStatus(409);
});

it('lets only the producer (or the owner, for a scheduled run) re-download a stored export, and audits it', function () {
    Storage::fake('local');
    $owner = tenantUser($this->tenant, ['analytics.reports', 'analytics.export', 'employee.view']);
    $other = tenantUser($this->tenant, ['analytics.reports', 'analytics.export', 'employee.view']);
    $report = Report::create(['name' => 'People', 'dataset' => 'employees', 'definition' => ['fields' => ['employee_code']], 'is_shared' => true, 'owner_id' => $owner->id]);
    $run = app(ReportExports::class)->export($report, $owner);
    $exports = app(ReportExports::class);

    expect($exports->canDownload($run, $owner))->toBeTrue()->and($exports->canDownload($run, $other))->toBeFalse()
        ->and(fn () => $exports->download($run, $other))->toThrow(RuntimeException::class);
    $exports->download($run, $owner);
    expect(AuditEvent::query()->where('action', 'DOWNLOAD')->where('module', 'analytics')->where('metadata->redownload', true)->count())->toBe(1);

    // A run nobody produced (no runner, no schedule) is downloadable by no one.
    $orphan = ReportRun::create(['report_id' => $report->id, 'run_by' => null, 'format' => 'csv', 'status' => 'completed', 'disk' => 'local', 'path' => $run->path, 'started_at' => now()]);
    expect($exports->canDownload($orphan, $owner))->toBeFalse();
});
