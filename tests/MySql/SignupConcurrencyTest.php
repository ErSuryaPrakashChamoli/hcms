<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Services\SignupApplications;
use App\Domain\Signup\Services\SignupMaintenance;
use App\Domain\Signup\Services\SignupProvisioning;
use App\Domain\Signup\Services\SignupReview;
use App\Domain\Subscriptions\Models\TenantSubscription;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/ConcurrencyHelpers.php';
require_once __DIR__.'/../Feature/Signup/SignupTestHelpers.php';

/*
 | SaaS.10: real concurrency on MySQL for self-service signup. One open application per address (unique generated
 | column), one consumed link, one provisioning claim (conditional update + fencing token under the row lock), one
 | decision when an approval, a rejection and a cancellation collide, unique tenant addresses, and a lease expiry that
 | never undoes a run that is still committing. Fictional data in a disposable database only.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency',
        'queue.default' => 'sync', 'mail.default' => 'array', 'cache.default' => 'database']);
    DB::purge('concurrency');
    Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
    Notification::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    RateLimiter::clear('signup-start-ip:203.0.113.7');
    $this->catalogue = signupCatalogue();
});

afterEach(function () {
    expect(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
});

function submittedFor(string $email, string $display = 'Acme Signup'): SignupApplication
{
    return signupSubmitted(['display_name' => $display], $email);
}

it('1. keeps one open application when the same address starts twice at once', function () {
    $start = fn (string $ip) => fn () => app(SignupApplications::class)->start(['email' => 'twin@acme-race.test', 'name' => 'Twin Applicant', 'company' => 'Twin Co',
        'country' => 'IN', 'plan' => 'core'], $ip);
    $results = race([$start('198.51.100.1'), $start('198.51.100.2'), $start('198.51.100.3')], slow: ['eloquent.creating: '.SignupApplication::class]);

    expect($results)->toBe(['ok', 'ok', 'ok'])
        ->and(SignupApplication::query()->where('email', 'twin@acme-race.test')->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'SIGNUP_STARTED')->count())->toBe(1);
});

it('2. consumes a link once when it is confirmed twice at once', function () {
    signupStart(['email' => 'double@acme-race.test']);
    $token = lastSignupToken('double@acme-race.test');

    $results = race([fn () => app(SignupApplications::class)->confirm($token), fn () => app(SignupApplications::class)->confirm($token)]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->reject(fn ($r) => $r === 'ok')->values()->all())->toBe([SignupApplications::LINK_INVALID])
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'SIGNUP_EMAIL_VERIFIED')->count())->toBe(1)
        ->and(SignupApplication::query()->sole()->status)->toBe(SignupStatus::DetailsPending);
});

it('3. provisions once when two ticks and an operator retry pick the same application up together', function () {
    $application = submittedFor('claim@acme-race.test');
    $operator = platformAdmin();
    $tick = fn () => app(SignupMaintenance::class)->provisionDue();
    // The operator path needs a failed application; make the third racer a plain provisioning call as the console.
    $direct = fn () => app(SignupProvisioning::class)->provision(SignupApplication::query()->findOrFail($application->id));

    $results = race([$tick, $tick, $direct], slow: ['eloquent.creating: '.Tenant::class]);

    $application->refresh();
    expect($results)->toBe(['ok', 'ok', 'ok'])
        ->and($application->status)->toBe(SignupStatus::ActivationPending)->and($application->attempts)->toBe(1)
        ->and(Tenant::query()->count())->toBe(1)
        ->and(TenantSubscription::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(User::query()->where('email', 'claim@acme-race.test')->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'SIGNUP_PROVISIONED')->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'SIGNUP_PROVISIONING_STARTED')->count())->toBe(1)
        ->and(app(AuditIntegrityVerifier::class)->verify((int) $application->provisioned_tenant_id)['valid'])->toBeTrue();
});

it('4. decides once when an approval, a rejection and a cancellation arrive together', function () {
    signupPolicy(ConfigurationKey::SignupReview, 'all', platformAdmin(), platformAdmin());
    $application = submittedFor('decide@acme-race.test');
    [$a, $b, $c] = [platformAdmin(), platformAdmin(), platformAdmin()];
    $act = fn (string $method, User $by, float $after) => function () use ($method, $by, $after, $application) {
        usleep((int) ($after * 1_000_000));
        app(SignupReview::class)->{$method}(SignupApplication::query()->findOrFail($application->id), User::query()->findOrFail($by->id), "Decision by {$method}");
    };

    $results = race([$act('approve', $a, 0), $act('reject', $b, 0), $act('cancel', $c, 0)]);

    $application->refresh();
    $outcomes = collect($results)->filter(fn ($r) => $r === 'ok');
    expect($outcomes)->toHaveCount(1)
        ->and($application->status)->toBeIn([SignupStatus::ActivationPending, SignupStatus::Rejected, SignupStatus::Cancelled])
        ->and(AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->whereIn('action', ['SIGNUP_APPROVED', 'SIGNUP_REJECTED', 'SIGNUP_CANCELLED'])->count())->toBe(1)
        ->and(Tenant::query()->count())->toBe($application->status === SignupStatus::ActivationPending ? 1 : 0);
});

it('5. gives two organisations with the same name two addresses, even when they are provisioned at once', function () {
    $first = submittedFor('one@first-race.test', 'Same Name Co');
    $this->travel(2)->minutes();
    $second = submittedFor('two@second-race.test', 'Same Name Co');
    $run = fn (int $id) => fn () => app(SignupProvisioning::class)->provision(SignupApplication::query()->findOrFail($id));

    race([$run($first->id), $run($second->id)], slow: ['eloquent.creating: '.Tenant::class]);
    // A collision on the address rolls one back; it is retried after its backoff and then takes the next address.
    $this->travel(2)->minutes();
    app(SignupMaintenance::class)->provisionDue();

    $slugs = Tenant::query()->orderBy('slug')->pluck('slug')->all();
    expect($first->fresh()->status)->toBe(SignupStatus::ActivationPending)->and($second->fresh()->status)->toBe(SignupStatus::ActivationPending)
        ->and($slugs)->toBe(['same-name-co', 'same-name-co-2']);
});

it('6. never undoes a run that is still committing when its lease expires meanwhile', function () {
    config(['peopleos.signup.provisioning.lease_minutes' => 0]);
    $application = submittedFor('slow@acme-race.test');
    $flag = tempnam(sys_get_temp_dir(), 'lease');
    unlink($flag);

    $provision = function () use ($application, $flag) {
        // Hold the run inside its transaction, after it locked the application row, until the tick has tried.
        $paused = false;
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$paused, $flag) {
            // The run's first read of the application inside its transaction (with or without the row lock).
            if (! $paused && str_contains($q->sql, 'from `signup_applications`') && DB::transactionLevel() > 0) {
                $paused = true;
                touch($flag.'.locked');
                $deadline = microtime(true) + 60;
                while (! file_exists($flag.'.tried') && microtime(true) < $deadline) {
                    usleep(20_000);
                }
                usleep(500_000); // the tick's update is now waiting on this row lock
            }
        });
        app(SignupProvisioning::class)->provision(SignupApplication::query()->findOrFail($application->id));
    };
    $tick = function () use ($flag) {
        $deadline = microtime(true) + 60;
        while (! file_exists($flag.'.locked') && microtime(true) < $deadline) {
            usleep(20_000);
        }
        DB::listen(function (QueryExecuted $q) use ($flag) {
            if (str_contains($q->sql, 'from `signup_applications`')) {
                touch($flag.'.tried');
            }
        });
        touch($flag.'.tried');
        app(SignupMaintenance::class)->recoverInterrupted();
    };

    $results = race([$provision, $tick]);
    $paused = file_exists($flag.'.locked');
    @unlink($flag.'.locked');
    @unlink($flag.'.tried');

    expect($paused)->toBeTrue() // the dangerous order really happened
        ->and($results)->toBe(['ok', 'ok'])
        ->and($application->fresh()->status)->toBe(SignupStatus::ActivationPending)
        ->and(Tenant::query()->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'SIGNUP_PROVISIONING_FAILED')->count())->toBe(0);
});

it('7. provisions several organisations at once without a deadlock, and every chain stays valid', function () {
    $applications = collect(range(1, 4))->map(function (int $i) {
        $this->travel(1)->seconds();

        return submittedFor("many{$i}@many-{$i}.test", "Many Co {$i}");
    });
    $deadlocks = fn () => (int) DB::selectOne("select count from information_schema.INNODB_METRICS where name = 'lock_deadlocks'")->count;
    $before = $deadlocks();

    // Four runs at once, each on its own application: the first events of four new tenant chains are written together.
    $results = race($applications->map(fn (SignupApplication $a) => fn () => app(SignupProvisioning::class)->provision(SignupApplication::query()->findOrFail($a->id)))->all());

    expect($results)->toBe(['ok', 'ok', 'ok', 'ok'])
        ->and($deadlocks() - $before)->toBe(0) // not hidden by a retry: none happened
        ->and($applications->map(fn (SignupApplication $a) => $a->fresh()->status)->unique()->values()->all())->toBe([SignupStatus::ActivationPending])
        ->and($applications->every(fn (SignupApplication $a) => $a->fresh()->attempts === 1))->toBeTrue();
    foreach ($applications as $application) {
        expect(app(AuditIntegrityVerifier::class)->verify((int) $application->fresh()->provisioned_tenant_id)['valid'])->toBeTrue();
    }
});
