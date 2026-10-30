<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PasswordPolicy;
use App\Domain\Identity\Services\PasswordResets;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Auth\ChangeExpiredPassword;
use App\Filament\Pages\Home;
use App\Filament\Pages\SecurityPolicyPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| HROne setup parity P1 (Governance → Security: password policy, disable login): password expiry is enforced (it was
| offered but never applied), with reminders before it; a strict password can need a symbol and avoid the person's own
| details; recent passwords can be refused; lifecycle states can turn sign-in off and back on. Every default keeps the
| earlier behaviour.
*/

beforeEach(function () {
    $this->tenant = provisionTenant('Alpha');
    $this->other = provisionTenant('Beta');
    $this->user = tenantUser($this->tenant, ['employee.view'], ['name' => 'Asha Verma', 'email' => 'asha@alpha.test', 'password' => 'Old-Password-1']);
    $this->expiredRoute = route('filament.admin.auth.password-expired');
});

function secPolicySet(Tenant $tenant, string $key, mixed $value): void
{
    app(TenantContext::class)->runAs($tenant, fn () => app(SettingsRepository::class)->set($key, $value, 'Security test'));
}

function secPolicyAgePassword(User $user, int $days): void
{
    User::query()->whereKey($user->id)->update(['password_changed_at' => now()->subDays($days)]);
}

/* ---------- Password expiry (the finding: it was never enforced) ---------- */

it('keeps today\'s behaviour while passwords never expire, however old the password is', function () {
    secPolicyAgePassword($this->user, 4000);
    Auth::guard('web')->login($this->user->fresh());

    $this->get(Home::getUrl())->assertOk();
});

it('sends a password session to the change page once the password is older than the expiry, on every page', function () {
    secPolicySet($this->tenant, 'security.password_expiry_days', 30);
    secPolicyAgePassword($this->user, 31);
    Auth::guard('web')->login($this->user->fresh());

    $this->get(Home::getUrl())->assertRedirect($this->expiredRoute);
    $this->getJson(Home::getUrl())->assertForbidden();
    $this->get($this->expiredRoute)->assertOk()->assertSee('Choose a new password');
});

it('lets a younger password through, never applies one tenant\'s expiry to another, and leaves SSO sessions alone', function () {
    secPolicySet($this->tenant, 'security.password_expiry_days', 30);
    secPolicyAgePassword($this->user, 10);
    Auth::guard('web')->login($this->user->fresh());
    $this->get(Home::getUrl())->assertOk();

    $beta = tenantUser($this->other, ['employee.view']);
    secPolicyAgePassword($beta, 400);
    Auth::guard('web')->login($beta->fresh());
    $this->get(Home::getUrl())->assertOk();

    secPolicyAgePassword($this->user, 90);
    Auth::guard('web')->login($this->user->fresh());
    session()->put('auth.method', 'sso');
    $this->get(Home::getUrl())->assertOk();
});

it('treats a password with no known change date as expired (fails closed) and never applies tenant expiry to operators', function () {
    secPolicySet($this->tenant, 'security.password_expiry_days', 30);
    User::query()->whereKey($this->user->id)->update(['password_changed_at' => null]);
    Auth::guard('web')->login($this->user->fresh());
    $this->get(Home::getUrl())->assertRedirect($this->expiredRoute);

    actAsTenant(null);
    expect(app(SecurityPolicy::class)->passwordExpired(null))->toBeFalse(); // no tenant bound: platform defaults, never expire
});

it('changes the expired password: current password proved, policy applied, different from the current one, audited', function () {
    secPolicySet($this->tenant, 'security.password_expiry_days', 30);
    secPolicyAgePassword($this->user, 45);
    Auth::guard('web')->login($this->user->fresh());
    actAsTenant($this->tenant);

    Livewire::test(ChangeExpiredPassword::class)
        ->set('data.currentPassword', 'Wrong-Password-1')->set('data.password', 'Brand-New-Pass-7')->set('data.passwordConfirmation', 'Brand-New-Pass-7')
        ->call('change')->assertHasErrors(['data.currentPassword']);
    Livewire::test(ChangeExpiredPassword::class)
        ->set('data.currentPassword', 'Old-Password-1')->set('data.password', 'Old-Password-1')->set('data.passwordConfirmation', 'Old-Password-1')
        ->call('change')->assertHasErrors(['data.password']);
    Livewire::test(ChangeExpiredPassword::class)
        ->set('data.currentPassword', 'Old-Password-1')->set('data.password', 'short')->set('data.passwordConfirmation', 'short')
        ->call('change')->assertHasErrors(['data.password']);
    expect(Hash::check('Old-Password-1', $this->user->fresh()->password))->toBeTrue();

    Livewire::test(ChangeExpiredPassword::class)
        ->set('data.currentPassword', 'Old-Password-1')->set('data.password', 'Brand-New-Pass-7')->set('data.passwordConfirmation', 'Brand-New-Pass-7')
        ->call('change')->assertHasNoErrors()->assertRedirect();

    $fresh = $this->user->fresh();
    expect(Hash::check('Brand-New-Pass-7', $fresh->password))->toBeTrue()
        ->and($fresh->password_changed_at->gt(now()->subMinute()))->toBeTrue()
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'PASSWORD_CHANGED')->where('entity_id', (string) $fresh->id)->get()
            ->contains(fn ($e) => ($e->metadata['method'] ?? null) === 'expired'))->toBeTrue();
    $this->get(Home::getUrl())->assertOk();
});

it('reminds people once before their password expires, only while expiry is on', function () {
    $this->artisan('peopleos:lifecycle:reminders', ['--tenant' => $this->tenant->slug, '--force' => true])->assertSuccessful();
    expect(NotificationDelivery::query()->withoutGlobalScopes()->where('event', 'security.password_expiring')->count())->toBe(0);

    secPolicySet($this->tenant, 'security.password_expiry_days', 30);
    secPolicySet($this->tenant, 'security.password_expiry_warning_days', 7);
    secPolicyAgePassword($this->user, 25); // expires in 5 days
    $later = tenantUser($this->tenant, [], ['password' => 'Some-Password-1']);
    secPolicyAgePassword($later, 10); // expires in 20 days: not yet

    $this->artisan('peopleos:lifecycle:reminders', ['--tenant' => $this->tenant->slug, '--force' => true])->assertSuccessful();
    $this->artisan('peopleos:lifecycle:reminders', ['--tenant' => $this->tenant->slug, '--force' => true])->assertSuccessful();

    $sent = NotificationDelivery::query()->withoutGlobalScopes()->where('event', 'security.password_expiring')->get();
    expect($sent->pluck('user_id')->unique()->all())->toBe([$this->user->id])
        ->and($sent->where('channel', 'in_app'))->toHaveCount(1)
        ->and($sent->first()->subject)->toContain(now()->addDays(5)->format('d M Y'));
});

/* ---------- Strict password, personal details, recent passwords ---------- */

it('adds the symbol and personal-details rules only when switched on', function () {
    $policy = app(PasswordPolicy::class);
    expect($policy->problemsFor($this->user, 'AshaPassword99'))->toBe([]);

    secPolicySet($this->tenant, 'security.password_require_symbol', true);
    expect($policy->problemsFor($this->user, 'AshaPassword99'))->toBe(['Include a symbol (for example ! # % or @).'])
        ->and($policy->problemsFor($this->user, 'Asha-Password99'))->toBe([]);

    secPolicySet($this->tenant, 'security.password_block_personal', true);
    expect(implode(' ', $policy->problemsFor($this->user, 'Asha-Password99')))->toContain('name, e-mail or employee code')
        ->and(implode(' ', $policy->problemsFor($this->user, 'Verma#Strong12')))->toContain('name, e-mail or employee code')
        ->and($policy->problemsFor($this->user, 'Quiet#Harbour12'))->toBe([]);

    actAsTenant($this->tenant);
    $employee = Employee::factory()->create(['user_id' => $this->user->id, 'employee_code' => 'MKT-0042']);
    expect(implode(' ', $policy->problemsFor($this->user, 'Xmkt-0042-Lake')))->toContain('employee code')
        ->and($employee->employee_code)->toBe('MKT-0042');
});

it('refuses recent passwords when reuse is restricted, keeping only as many hashes as needed', function () {
    $policy = app(PasswordPolicy::class);
    expect($policy->problemsFor($this->user, 'Old-Password-1'))->toBe([]); // off: today's behaviour

    secPolicySet($this->tenant, 'security.password_history_count', 2);
    // The current password counts even though it was set before the rule.
    expect(implode(' ', $policy->problemsFor($this->user, 'Old-Password-1')))->toContain('last 2 passwords');

    $user = $this->user->fresh();
    $user->forceFill(['password' => 'Second-Pass-22'])->save();
    $user->forceFill(['password' => 'Third-Pass-333'])->save();
    $user = $user->fresh();
    expect($policy->problemsFor($user, 'Third-Pass-333'))->not->toBe([])
        ->and($policy->problemsFor($user, 'Second-Pass-22'))->not->toBe([])
        ->and($policy->problemsFor($user, 'Old-Password-1'))->toBe([]) // older than the last two
        ->and(DB::table('password_histories')->where('user_id', $user->id)->count())->toBe(2)
        ->and(DB::table('password_histories')->where('user_id', $user->id)->value('tenant_id'))->toBe($this->tenant->id);

    // A reset link applies the same rule (the policy is read in the user's own tenant).
    actAsTenant(null);
    $token = Password::broker()->createToken($user);
    expect(fn () => app(PasswordResets::class)->reset('asha@alpha.test', $token, 'Second-Pass-22'))->toThrow(ValidationException::class);
    expect(app(PasswordResets::class)->reset('asha@alpha.test', $token, 'Fourth-Pass-4444'))->toBeTrue();
});

it('offers the new rules in the Setup centre and on the security policy page', function () {
    actAsTenant($this->tenant);
    $admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($admin);
    proveMfa($admin);

    $changed = app(SetupCatalogue::class)->save('governance', [
        SetupCatalogue::field('security.password_history_count') => 3,
        SetupCatalogue::field('security.disable_login_states') => ['suspended', 'on_leave'],
    ], 'Tighter security', $admin);
    expect($changed)->toBe(['security.password_history_count', 'security.disable_login_states'])
        ->and(app(SecurityPolicy::class)->loginDisabledStates())->toBe(['on_leave', 'suspended']);
    expect(fn () => app(SetupCatalogue::class)->save('governance', [SetupCatalogue::field('security.disable_login_states') => ['active']], 'Lock everyone out', $admin))
        ->toThrow(RuntimeException::class, 'option that does not exist');

    Livewire::test(SecurityPolicyPage::class)
        ->set('data.security__password_require_symbol', true)->set('data.security__disable_login_states', ['notice_period'])
        ->call('save')->assertNotified('Security policy saved');
    expect(app(SettingsRepository::class)->get('security.password_require_symbol'))->toBeTrue()
        ->and(app(SettingsRepository::class)->get('security.disable_login_states'))->toBe(['notice_period']);
});

it('removed the two security defaults nothing read', function () {
    expect(array_key_exists('security.password.min_length', config('peopleos.settings')))->toBeFalse()
        ->and(array_key_exists('security.session.lifetime_minutes', config('peopleos.settings')))->toBeFalse();
});

/* ---------- Disable login by lifecycle state ---------- */

it('turns sign-in off when an employee enters a listed state and back on when they leave it', function () {
    actAsTenant($this->tenant);
    $employee = forceLifecycle(Employee::factory()->create(['user_id' => $this->user->id]), LifecycleState::Active);

    // Default: nothing listed, nothing changes.
    app(LifecycleEngine::class)->transition($employee, LifecycleState::Suspended, now(), 'Inquiry');
    expect($this->user->fresh()->status)->toBe(UserStatus::Active);
    app(LifecycleEngine::class)->transition($employee->fresh(), LifecycleState::Active, now(), 'Back');

    secPolicySet($this->tenant, 'security.disable_login_states', ['suspended']);
    app(LifecycleEngine::class)->transition($employee->fresh(), LifecycleState::Suspended, now(), 'Inquiry');
    $user = $this->user->fresh();
    expect($user->status)->toBe(UserStatus::Suspended)->and($user->disabled_by_rule)->toBeTrue();
    expect(AuditEvent::query()->withoutTenancy()->where('entity_type', User::class)->where('entity_id', (string) $user->id)->pluck('reason')->filter()->implode(' | '))
        ->toContain('Login disabled by status Suspended');

    // A suspended account is signed out at its next request.
    actAsTenant(null);
    Auth::guard('web')->login($user);
    $this->get(Home::getUrl())->assertRedirect();
    $this->assertGuest();

    actAsTenant($this->tenant);
    app(LifecycleEngine::class)->transition($employee->fresh(), LifecycleState::Active, now(), 'Cleared');
    expect($this->user->fresh()->status)->toBe(UserStatus::Active)->and($this->user->fresh()->disabled_by_rule)->toBeFalse();
});

it('never reactivates an account someone else suspended, and catches up daily on states listed later', function () {
    actAsTenant($this->tenant);
    $employee = forceLifecycle(Employee::factory()->create(['user_id' => $this->user->id]), LifecycleState::Active);
    secPolicySet($this->tenant, 'security.disable_login_states', ['suspended']);

    // An administrator suspended the account by hand: leaving the state does not undo that.
    $this->user->fresh()->forceFill(['status' => UserStatus::Suspended])->save();
    app(LifecycleEngine::class)->transition($employee->fresh(), LifecycleState::Suspended, now(), 'Inquiry');
    app(LifecycleEngine::class)->transition($employee->fresh(), LifecycleState::Active, now(), 'Cleared');
    expect($this->user->fresh()->status)->toBe(UserStatus::Suspended);

    // A rule suspension taken over by an administrator's change of status is theirs, not the rule's.
    $this->user->fresh()->forceFill(['status' => UserStatus::Active])->save();
    app(LifecycleEngine::class)->transition($employee->fresh(), LifecycleState::Suspended, now(), 'Inquiry');
    expect($this->user->fresh()->disabled_by_rule)->toBeTrue();
    $this->user->fresh()->forceFill(['status' => UserStatus::Active])->save();
    expect($this->user->fresh()->disabled_by_rule)->toBeFalse();

    // Listed after the fact: the daily catch-up applies it, and lifts it when the state is no longer listed.
    $other = tenantUser($this->tenant);
    $onLeave = forceLifecycle(Employee::factory()->create(['user_id' => $other->id]), LifecycleState::OnLeave);
    secPolicySet($this->tenant, 'security.disable_login_states', ['on_leave']);
    actAsTenant(null);
    $this->artisan('peopleos:security:login-status', ['--tenant' => $this->tenant->slug])->assertSuccessful();
    expect($other->fresh()->status)->toBe(UserStatus::Suspended)->and($other->fresh()->disabled_by_rule)->toBeTrue();

    secPolicySet($this->tenant, 'security.disable_login_states', []);
    $this->artisan('peopleos:security:login-status', ['--tenant' => $this->tenant->slug])->assertSuccessful();
    expect($other->fresh()->status)->toBe(UserStatus::Active)
        ->and($onLeave->fresh()->lifecycle_state)->toBe(LifecycleState::OnLeave);
});

it('never lists a working state, so one setting cannot lock the workforce out', function () {
    actAsTenant($this->tenant);
    $employee = forceLifecycle(Employee::factory()->create(['user_id' => $this->user->id]), LifecycleState::OnLeave);
    secPolicySet($this->tenant, 'security.disable_login_states', ['active', 'confirmed']);

    expect(app(SecurityPolicy::class)->loginDisabledStates())->toBe([]);
    app(LifecycleEngine::class)->transition($employee, LifecycleState::Active, now(), 'Back');
    expect($this->user->fresh()->status)->toBe(UserStatus::Active);
});
