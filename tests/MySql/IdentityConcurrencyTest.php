<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserInvitation;
use App\Domain\Identity\Notifications\InvitationLink;
use App\Domain\Identity\Services\PasswordResets;
use App\Domain\Identity\Services\UserInvitations;
use App\Domain\Integration\Models\ApiIdempotencyKey;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Support\Tenancy\TenantContext;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | SaaS.2 §23: real concurrency on MySQL for the identity and tenant-governance changes. Same harness and
 | opt-in as the earlier suites (PEOPLEOS_MYSQL_CONCURRENCY_DB, name containing "concurrency"); SQLite runs
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
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->tenant = provisionTenant('Identity race '.uniqid());
    actAsTenant($this->tenant);
    $this->events = fn (string $action) => AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', $action)->count();
});

afterEach(function () {
    if (isset($this->tenant)) {
        expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
    }
});

/** Writes slowed down so a missing lock or conditional update would let both writers through. */
function identitySlowEvents(): array
{
    return ['eloquent.updating: '.User::class, 'eloquent.updating: '.UserInvitation::class, 'eloquent.saving: '.User::class];
}

it('1. activates an invited account once when the same invitation link is submitted twice at once', function () {
    $invitee = tenantUser($this->tenant, [], ['email' => 'race-'.uniqid().'@alpha.test', 'status' => UserStatus::Invited]);
    Notification::fake();
    app(UserInvitations::class)->issue($invitee);
    $token = null;
    Notification::assertSentTo($invitee, InvitationLink::class, function (InvitationLink $n) use (&$token) {
        $token = Str::afterLast($n->toMail(new stdClass)->actionUrl, '/');

        return true;
    });

    $accept = fn (string $password) => fn () => app(UserInvitations::class)->accept($token, $password);
    $results = race([$accept('First-Password-11'), $accept('Second-Password-22')], slow: identitySlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toBe(UserInvitations::INVALID)
        ->and($invitee->fresh()->status)->toBe(UserStatus::Active)
        ->and(($this->events)('INVITATION_ACCEPTED'))->toBe(1);
});

it('2. changes the password once when the same reset link is submitted twice at once', function () {
    $user = tenantUser($this->tenant, [], ['email' => 'reset-'.uniqid().'@alpha.test']);
    $token = Password::broker()->createToken($user);

    // Each child resolves its own password broker: one resolved before the fork would hold the parent's connection
    // object, whose reconnect drops the child's live connection (and its transaction and row lock) mid-race.
    $reset = fn (string $password) => function () use ($user, $token, $password) {
        app()->forgetInstance('auth.password');
        Password::clearResolvedInstances();

        return app(PasswordResets::class)->reset($user->email, $token, $password) ?: throw new RuntimeException('invalid');
    };
    $results = race([$reset('Winner-Password-11'), $reset('Loser-Password-22')], slow: identitySlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(($this->events)('PASSWORD_CHANGED'))->toBe(1)
        ->and(Hash::check('Winner-Password-11', $user->fresh()->password) xor Hash::check('Loser-Password-22', $user->fresh()->password))->toBeTrue();
});

it('3. keeps the first authenticator when two set-ups race', function () {
    $user = tenantUser($this->tenant, ['employee.view']);
    $secrets = [app(AppAuthentication::class)->generateSecret(), app(AppAuthentication::class)->generateSecret()];
    $setUp = fn (string $secret) => fn () => User::query()->findOrFail($user->id)->saveAppAuthenticationSecret($secret);

    $results = race([$setUp($secrets[0]), $setUp($secrets[1])], slow: identitySlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and($secrets)->toContain($user->fresh()->getAppAuthenticationSecret())
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'MFA_CHANGED')->where('entity_id', (string) $user->id)->count())->toBe(1);
});

it('4. suspends once, with one audit trail and one epoch change, when two operators suspend at once', function () {
    $epoch = (int) DB::table('tenants')->where('id', $this->tenant->id)->value('session_epoch');
    $suspend = fn (string $reason) => fn () => app(TenantSuspensions::class)->suspend(Tenant::query()->findOrFail($this->tenant->id), $reason, null) ?: throw new RuntimeException('no change');

    $results = race([$suspend('Operator one'), $suspend('Operator two')]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Suspended)
        ->and((int) DB::table('tenants')->where('id', $this->tenant->id)->value('session_epoch'))->toBe($epoch + 1)
        ->and(($this->events)('TENANT_SUSPENDED'))->toBe(1);
});

it('5. runs the action once when two requests re-use the same expired Idempotency-Key at once', function () {
    $company = Company::factory()->create(['code' => 'C'.random_int(1000, 9999)]);
    $key = app(ApiKeys::class)->issue('Writer', ['employees.write', 'employees.read'])['plaintext'];
    $payload = ['person' => ['first_name' => 'Expired', 'last_name' => 'Key'], 'employee' => ['joining_date' => '2026-01-01'], 'position' => ['company_code' => $company->code]];
    $this->withHeaders(['X-Api-Key' => $key, 'Idempotency-Key' => 'race-expired-01'])->postJson('/api/v1/employees', $payload)->assertCreated();
    ApiIdempotencyKey::query()->update(['expires_at' => now()->subMinute()]);
    $again = ['person' => ['first_name' => 'Reused', 'last_name' => 'Key']] + $payload;

    $post = fn () => tap($this->withHeaders(['X-Api-Key' => $key, 'Idempotency-Key' => 'race-expired-01'])->postJson('/api/v1/employees', $again),
        fn ($r) => in_array($r->status(), [201, 409], true) || throw new RuntimeException('status '.$r->status().' '.$r->content()));
    $results = race([$post, $post], slow: ['eloquent.creating: '.Employee::class]);

    expect($results)->toBe(['ok', 'ok'])
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => Employee::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->whereHas('person', fn ($q) => $q->where('first_name', 'Reused'))->count()))->toBe(1);
});
