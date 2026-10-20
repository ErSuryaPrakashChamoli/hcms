<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Entitlements\Models\TenantPlanAssignment;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\InvitationLink;
use App\Domain\Identity\Services\UserInvitations;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Signup\Notifications\SignupUpdate;
use App\Domain\Signup\Services\SignupApplications;
use App\Domain\Signup\Services\SignupMaintenance;
use App\Domain\Signup\Services\SignupProvisioning;
use App\Domain\Signup\Services\SignupReview;
use App\Domain\Signup\Support\SignupRefused;
use App\Domain\Signup\Support\StaleClaim;
use App\Domain\Subscriptions\Models\SubscriptionPeriod;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Filament\Pages\PlatformOperationsPage;
use App\Filament\Pages\PlatformSignupsPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

require_once __DIR__.'/SignupTestHelpers.php';

/*
 | SaaS.10: provisioning. One transaction for tenant, owner and trial; invitation after the commit; idempotent claims;
 | recovery after a failure, an interruption or a refused e-mail; the owner's boundary; operators only, audited.
 */

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('signup-start-ip:203.0.113.7');
    $this->catalogue = signupCatalogue();
});

function invitationToken(User $owner): string
{
    $url = null;
    Notification::assertSentTo($owner, InvitationLink::class, function (InvitationLink $n) use ($owner, &$url) {
        $url = $n->toMail($owner)->actionUrl;

        return true;
    });

    return basename((string) parse_url((string) $url, PHP_URL_PATH));
}

function tenantCounts(): array
{
    return ['tenants' => Tenant::query()->count(), 'users' => User::query()->count(), 'subscriptions' => TenantSubscription::query()->withoutGlobalScopes()->count()];
}

it('provisions an approved application in one step: trial tenant, an invited Account Owner, the trial, then the invitation', function () {
    $application = signupSubmitted();
    $result = app(SignupMaintenance::class)->run();

    $application->refresh();
    $tenant = Tenant::query()->findOrFail($application->provisioned_tenant_id);
    $owner = User::query()->findOrFail($application->provisioned_user_id);
    $subscription = TenantSubscription::query()->withoutGlobalScopes()->findOrFail($application->subscription_id);
    $period = SubscriptionPeriod::query()->withoutGlobalScopes()->where('subscription_id', $subscription->id)->sole();

    expect($result['provisioned'])->toBe(1)
        ->and($application->status)->toBe(SignupStatus::ActivationPending)->and($application->attempts)->toBe(1)
        ->and($tenant->status)->toBe(TenantStatus::Trial)->and($tenant->slug)->toBe('acme-signup')->and($tenant->name)->toBe('Acme Signup')
        ->and($tenant->timezone)->toBe('Asia/Kolkata')->and($tenant->currency)->toBe('INR')->and($tenant->country_code)->toBe('IN')
        ->and($owner->tenant_id)->toBe($tenant->id)->and($owner->status)->toBe(UserStatus::Invited)->and($owner->isPlatformAdmin())->toBeFalse()
        ->and($owner->is_platform_admin)->toBeFalse()->and(app(TenantContext::class)->runAs($tenant, fn () => $owner->roleSlugs()))->toBe(['account-owner'])
        ->and(app(TenantContext::class)->runAs($tenant, fn () => $owner->permissionKeys()->all()))->toContain('user.assign_roles', 'company.update')
        ->and(app(TenantContext::class)->runAs($tenant, fn () => $owner->fresh()->permissionKeys()->all()))->not->toContain('payroll.view')
        ->and($subscription->tenant_id)->toBe($tenant->id)
        ->and($period->status->value)->toBe('trial')->and($period->trigger)->toBe('signup')->and((int) $period->plan_version_id)->toBe($this->catalogue['version']->id)
        ->and($period->starts_on->toDateString())->toBe(now()->toDateString())->and($period->ends_on->toDateString())->toBe(now()->addDays(29)->toDateString())
        ->and($application->invitation_sent_at)->not->toBeNull();
    Notification::assertSentTo($owner, InvitationLink::class);

    $actions = AuditEvent::query()->withoutTenancy()->where('module', 'signup')->pluck('action')->map->value->all();
    expect($actions)->toContain('SIGNUP_PROVISIONING_STARTED', 'SIGNUP_PROVISIONED', 'SIGNUP_EMAIL_SENT')
        ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue()
        ->and(app(AuditIntegrityVerifier::class)->verify($tenant->id)['valid'])->toBeTrue();
});

it('is idempotent: running again, or the tick again, never creates a second tenant, owner or trial', function () {
    $application = signupSubmitted();
    app(SignupMaintenance::class)->run();
    $after = tenantCounts();

    expect(app(SignupProvisioning::class)->provision($application))->toBeNull();
    app(SignupMaintenance::class)->run();
    app(SignupMaintenance::class)->run();

    expect(tenantCounts())->toBe($after)->and($application->fresh()->attempts)->toBe(1);
    Notification::assertSentToTimes(User::query()->findOrFail($application->fresh()->provisioned_user_id), InvitationLink::class, 1);
});

it('rolls back everything when a step fails, retries after the backoff, and then provisions exactly once', function () {
    $application = signupSubmitted();
    $before = tenantCounts();
    $real = app(CommercialSubscriptions::class);
    $calls = 0;
    app()->instance(CommercialSubscriptions::class, Mockery::mock($real)->makePartial()->shouldReceive('startSignupTrial')
        ->andReturnUsing(function (...$args) use (&$calls, $real) {
            if (++$calls === 1) {
                throw new RuntimeException('Simulated failure with priya@acme-signup.test in the message');
            }

            return $real->startSignupTrial(...$args);
        })->getMock());

    app(SignupMaintenance::class)->run();
    $application->refresh();
    expect($application->status)->toBe(SignupStatus::FailedRetryable)
        ->and($application->failure_code)->toBe('PROVISIONING_ERROR')
        ->and($application->failure_summary)->not->toContain('priya')
        ->and($application->failure_summary)->not->toContain('Simulated')
        ->and($application->next_attempt_at->gt(now()))->toBeTrue()
        ->and(tenantCounts())->toBe($before); // tenant, roles, owner: all rolled back

    app(SignupMaintenance::class)->run(); // backoff not over
    expect($application->fresh()->status)->toBe(SignupStatus::FailedRetryable)->and($application->fresh()->attempts)->toBe(1);

    $this->travel(2)->minutes();
    app(SignupMaintenance::class)->run();
    expect($application->fresh()->status)->toBe(SignupStatus::ActivationPending)->and($application->fresh()->attempts)->toBe(2)
        ->and(tenantCounts()['tenants'])->toBe($before['tenants'] + 1);
});

it('stops for a person after the last attempt, and tells the applicant without details', function () {
    config(['peopleos.signup.provisioning.max_attempts' => 1]);
    $application = signupSubmitted();
    app()->instance(CommercialSubscriptions::class, Mockery::mock(app(CommercialSubscriptions::class))->makePartial()
        ->shouldReceive('startSignupTrial')->andThrow(new RuntimeException('boom'))->getMock());

    app(SignupMaintenance::class)->run();

    expect($application->fresh()->status)->toBe(SignupStatus::FailedManualReview)->and($application->fresh()->next_attempt_at)->toBeNull();
    Notification::assertSentOnDemand(SignupUpdate::class);
    $this->travel(2)->hours();
    app(SignupMaintenance::class)->run(); // the tick never retries a manual review
    expect($application->fresh()->status)->toBe(SignupStatus::FailedManualReview)->and($application->fresh()->attempts)->toBe(1);
});

it('re-checks eligibility at provisioning: an offer withdrawn, a plan version superseded or an address taken stops it for review', function () {
    $application = signupSubmitted();
    signupPolicy(ConfigurationKey::SignupOffers, 'core:30:AE', platformAdmin(), platformAdmin());

    app(SignupMaintenance::class)->run();
    expect($application->fresh()->status)->toBe(SignupStatus::FailedManualReview)->and($application->fresh()->failure_code)->toBe('NOT_OFFERED')
        ->and(Tenant::query()->count())->toBe(0);

    signupPolicy(ConfigurationKey::SignupOffers, 'core:30:IN', platformAdmin(), platformAdmin());
    $tenant = provisionTenant('Taken Co');
    app(TenantContext::class)->runAs($tenant, fn () => User::factory()->forTenant($tenant)->create(['email' => 'priya@acme-signup.test']));
    app(SignupReview::class)->retry($application, platformAdmin(), 'Offer restored; retry');
    expect($application->fresh()->failure_code)->toBe('EMAIL_IN_USE')->and(Tenant::query()->count())->toBe(1);
});

it('recovers a set-up interrupted before its commit (lease), and a stale run never acts after another claimed it', function () {
    $application = signupSubmitted();
    SignupApplication::query()->whereKey($application->id)->update(['status' => 'provisioning', 'claim_token' => (string) Str::ulid(),
        'claimed_at' => now()->subMinutes(20), 'attempts' => 1]);

    $result = app(SignupMaintenance::class)->run();

    expect($result['recovered'])->toBe(1)->and($result['provisioned'])->toBe(1)
        ->and($application->fresh()->status)->toBe(SignupStatus::ActivationPending)->and(Tenant::query()->count())->toBe(1);

    // A run whose claim is no longer current stops before creating anything.
    $other = signupSubmitted([], 'other@second-co.test');
    SignupApplication::query()->whereKey($other->id)->update(['status' => 'provisioning', 'claim_token' => 'A'.str_repeat('0', 25), 'claimed_at' => now()]);
    expect(app(SignupProvisioning::class)->provision($other))->toBeNull()->and(Tenant::query()->count())->toBe(1);
});

it('records a refused owner invitation and sends it again from the tick', function () {
    $application = signupSubmitted();
    // From here on, e-mails really go through the mailer: first a transport that refuses, then one that accepts.
    Notification::swap(new ChannelManager(app()));
    config(['mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'timeout' => 1], 'mail.default' => 'broken']);

    app(SignupMaintenance::class)->run();
    $application->refresh();
    expect($application->status)->toBe(SignupStatus::ActivationPending)->and($application->invitation_sent_at)->toBeNull()
        ->and($application->invitation_failures)->toBe(1)->and(Tenant::query()->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'SIGNUP_EMAIL_FAILED')->count())->toBe(1);

    config(['mail.default' => 'array']);
    app('mail.manager')->forgetMailers();
    app(SignupMaintenance::class)->run(); // within five minutes: not yet
    expect($application->fresh()->invitation_sent_at)->toBeNull();
    $this->travel(6)->minutes();
    app(SignupMaintenance::class)->run();
    expect($application->fresh()->invitation_sent_at)->not->toBeNull()
        ->and(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count())->toBe(1);
});

it('marks the application active when the owner chooses a password, and removes their name and address from it', function () {
    $application = signupSubmitted();
    app(SignupMaintenance::class)->run();
    $owner = User::query()->findOrFail($application->fresh()->provisioned_user_id);
    app(UserInvitations::class)->accept(invitationToken($owner), 'A-Long-Owner-Passphrase-2026!');

    app(SignupMaintenance::class)->run();
    $application->refresh();
    expect($application->status)->toBe(SignupStatus::Active)->and($application->activated_at)->not->toBeNull()
        ->and($application->email)->toBeNull()->and($application->contact_name)->toBeNull()->and($application->email_hash)->toBeNull()
        ->and($application->terms_version)->toBe('2026.1')->and($application->acknowledged_at)->not->toBeNull()
        ->and($owner->fresh()->status)->toBe(UserStatus::Active)->and($owner->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('lets the owner reach only their own tenant, never platform operations, and gives them no paid entitlement', function () {
    $other = provisionTenant('Neighbour Co');
    $application = signupSubmitted();
    app(SignupMaintenance::class)->run();
    $owner = User::query()->findOrFail($application->fresh()->provisioned_user_id);
    app(UserInvitations::class)->accept(invitationToken($owner), 'A-Long-Owner-Passphrase-2026!');
    $owner = $owner->fresh();

    expect($owner->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeTrue()
        ->and($owner->tenant_id)->not->toBe($other->id)
        ->and(PlatformOperationsPage::canAccess())->toBeFalse();
    $this->actingAs($owner);
    expect(PlatformOperationsPage::canAccess())->toBeFalse()
        ->and(PlatformSignupsPage::canAccess())->toBeFalse()
        ->and($owner->can('view', $other))->toBeFalse();

    // The trial is the only commercial state: one plan assignment, from the trial, for the trial's dates.
    $assignments = TenantPlanAssignment::query()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->get();
    expect($assignments)->toHaveCount(1)->and($assignments->first()->commercial_status)->toBe('trial')
        ->and(TenantPlanAssignment::query()->withoutGlobalScopes()->where('tenant_id', $other->id)->count())->toBe(0);
});

it('lets only operators approve, reject, retry, hold or cancel, and names them in the audit', function () {
    signupPolicy(ConfigurationKey::SignupReview, 'all', platformAdmin(), platformAdmin());
    $application = signupSubmitted();
    expect($application->status)->toBe(SignupStatus::ReviewPending);
    $tenantAdmin = tenantUser(provisionTenant('Some Customer'), ['*']);

    foreach (['approve', 'reject', 'retry', 'holdForReview', 'cancel', 'resendActivation'] as $action) {
        expect(fn () => app(SignupReview::class)->{$action}($application, $tenantAdmin, 'Trying my luck'))->toThrow(RuntimeException::class, 'Only platform operators');
        expect(fn () => app(SignupReview::class)->{$action}($application, null, 'Trying my luck'))->toThrow(SignupRefused::class, 'Only platform operators');
    }
    expect($application->fresh()->status)->toBe(SignupStatus::ReviewPending);

    $operator = platformAdmin();
    $tenant = app(SignupReview::class)->approve($application, $operator, 'Known prospect, contract signed');
    expect($tenant)->not->toBeNull()->and($application->fresh()->status)->toBe(SignupStatus::ActivationPending)
        ->and($application->fresh()->decided_by)->toBe($operator->id);
    $approved = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'SIGNUP_APPROVED')->sole();
    expect($approved->actor_id)->toBe($operator->id)->and($approved->reason)->toBe('Known prospect, contract signed');
    expect(fn () => app(SignupReview::class)->reject($application, $operator, 'Too late'))->toThrow(SignupRefused::class);
});

it('rejects with a neutral e-mail that does not carry the operator\'s reason', function () {
    signupPolicy(ConfigurationKey::SignupReview, 'all', platformAdmin(), platformAdmin());
    $application = signupSubmitted();

    app(SignupReview::class)->reject($application, platformAdmin(), 'Internal note: suspected trial abuse');

    expect($application->fresh()->status)->toBe(SignupStatus::Rejected);
    Notification::assertSentOnDemand(SignupUpdate::class, function (SignupUpdate $n, array $channels, $notifiable) {
        $mail = $n->toMail($notifiable);

        return str_contains((string) $mail->subject, 'not approved') && ! str_contains(implode(' ', $mail->introLines), 'abuse');
    });
    expect(fn () => app(SignupProvisioning::class)->provision($application))->not->toThrow(Throwable::class);
    expect(Tenant::query()->count())->toBe(0);
});

it('provisions a reviewed offer without a trial as an active tenant with no subscription, never a paid one', function () {
    signupPolicy(ConfigurationKey::SignupOffers, 'core:0:IN', platformAdmin(), platformAdmin());
    $application = signupSubmitted();
    expect($application->status)->toBe(SignupStatus::ReviewPending);

    $tenant = app(SignupReview::class)->approve($application, platformAdmin(), 'Contract signed offline');

    expect($tenant->status)->toBe(TenantStatus::Active)->and($application->fresh()->subscription_id)->toBeNull()
        ->and(TenantSubscription::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(TenantPlanAssignment::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('derives a unique, non-reserved tenant address and never reuses an existing tenant', function () {
    $existing = app(ProvisionTenantAction::class)->handle(['name' => 'Acme Signup', 'slug' => 'acme-signup']);
    $application = signupSubmitted();
    app(SignupMaintenance::class)->run();

    $tenant = Tenant::query()->findOrFail($application->fresh()->provisioned_tenant_id);
    expect($tenant->id)->not->toBe($existing->id)->and($tenant->slug)->toBe('acme-signup-2')
        ->and(User::query()->where('tenant_id', $existing->id)->count())->toBe(0);

    $admin = signupSubmitted(['display_name' => 'Admin'], 'admin@third-co.test');
    app(SignupMaintenance::class)->run();
    expect(Tenant::query()->findOrFail($admin->fresh()->provisioned_tenant_id)->slug)->toBe('admin-org');
});

it('never writes a link, token or password into the logs during the whole flow', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context);
    });
    config(['peopleos.signup.provisioning.max_attempts' => 5]);
    signupStart();
    $token = lastSignupToken();
    $application = app(SignupApplications::class)->confirm($token);
    app()->instance(CommercialSubscriptions::class, Mockery::mock(app(CommercialSubscriptions::class))->makePartial()
        ->shouldReceive('startSignupTrial')->once()->andThrow(new RuntimeException('failure mentioning priya@acme-signup.test'))->getMock());
    app(SignupApplications::class)->submit($application, ['legal_name' => 'Acme Signup Private Limited', 'display_name' => 'Acme Signup',
        'timezone' => 'Asia/Kolkata', 'intended_employees' => 120, 'terms_version' => '2026.1', 'privacy_version' => '2026.1', 'authority' => true], '203.0.113.7');
    app(SignupMaintenance::class)->run();

    $all = implode("\n", $logged);
    expect($all)->toContain('Signup provisioning failed')
        ->and($all)->not->toContain($token)
        ->and($all)->not->toContain('priya@acme-signup.test')
        ->and($all)->not->toContain('Passphrase');
});

it('stops inside the transaction when the claim token is no longer current (a newer run owns it)', function () {
    $application = signupSubmitted();
    SignupApplication::query()->whereKey($application->id)->update(['status' => 'provisioning', 'claim_token' => 'B'.str_repeat('0', 25), 'claimed_at' => now(), 'attempts' => 1]);
    $core = Closure::bind(fn (int $id, string $token) => $this->core($id, $token, null), app(SignupProvisioning::class), SignupProvisioning::class);
    $created = 0;
    Event::listen('eloquent.creating: '.Tenant::class, function () use (&$created) {
        $created++;
    });

    expect(fn () => DB::transaction(fn () => $core($application->id, 'A'.str_repeat('0', 25))))->toThrow(StaleClaim::class);
    // It stops before doing any provisioning work, not only before committing it.
    expect($created)->toBe(0)->and(Tenant::query()->count())->toBe(0)->and($application->fresh()->status)->toBe(SignupStatus::Provisioning);
});

it('runs one provisioning at a time: a run that cannot get the lock claims nothing and leaves it for the tick', function () {
    config(['peopleos.signup.provisioning.wait_seconds' => 1]);
    $application = signupSubmitted();
    $held = Cache::lock(SignupProvisioning::LOCK, 60);
    expect($held->get())->toBeTrue();

    expect(app(SignupProvisioning::class)->provision($application))->toBeNull();
    expect($application->fresh()->status)->toBe(SignupStatus::Approved)->and($application->fresh()->attempts)->toBe(0)
        ->and(Tenant::query()->count())->toBe(0);

    $held->release();
    expect(app(SignupProvisioning::class)->provision($application))->not->toBeNull();
});
