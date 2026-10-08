<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserInvitation;
use App\Domain\Identity\Notifications\InvitationLink;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\TenantFirstAdministrator;
use App\Domain\Platform\Services\TenantProfiles;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| SaaS.8: provisioning, the first administrator's invitation and tenant edits are operator actions, guarded in their
| services and recorded on the platform chain; an invitation e-mail that fails never leaves a half-provisioned tenant.
*/

/** A mailer that cannot connect: the invitation e-mail fails the way an SMTP outage does. */
function breakMail(): void
{
    config(['mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'timeout' => 1], 'mail.default' => 'broken']);
    app('mail.manager')->forgetMailers();
}

function platformEvents(string $action)
{
    return AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', $action)->get();
}

beforeEach(function () {
    app(PermissionRegistry::class)->sync();
    $this->operator = platformAdmin();
    $this->provision = app(ProvisionTenantAction::class);
    $this->administrator = app(TenantFirstAdministrator::class);
});

it('keeps the tenant when the invitation e-mail fails, records the failure, and lets an operator send it again', function () {
    breakMail();
    $tenant = $this->provision->handle(['name' => 'Globex', 'slug' => 'globex'], ['name' => 'Gina Owner', 'email' => 'owner@globex.test'], 'New customer', $this->operator);

    $owner = User::query()->where('email', 'owner@globex.test')->sole();
    expect(Tenant::query()->whereKey($tenant->id)->exists())->toBeTrue()
        ->and($owner->status)->toBe(UserStatus::Invited)
        // Nothing pending that nobody received: the invitation row and its audit rolled back with the failed e-mail.
        ->and(UserInvitation::query()->withoutTenancy()->where('user_id', $owner->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'INVITATION_ISSUED')->exists())->toBeFalse()
        ->and($this->administrator->needsInvitation($tenant))->toBeTrue();
    $failed = AuditEvent::query()->withoutTenancy()->where('action', 'INVITATION_FAILED')->get();
    expect($failed)->toHaveCount(2)
        ->and($failed->pluck('tenant_id')->sort()->values()->all())->toBe([null, $tenant->id])
        ->and($failed->every(fn ($e) => $e->actor_id === $this->operator->id))->toBeTrue();

    // Mail works again: the operator sends it from the Tenants list.
    config(['mail.default' => 'array']);
    app('mail.manager')->forgetMailers();
    Notification::fake();
    $invitation = $this->administrator->send($tenant, $this->operator, 'Mail outage resolved');

    expect($invitation->isPending())->toBeTrue()->and($this->administrator->needsInvitation($tenant))->toBeFalse();
    Notification::assertSentTo($owner, InvitationLink::class);
    expect(platformEvents('INVITATION_ISSUED'))->toHaveCount(1)
        ->and(platformEvents('INVITATION_ISSUED')->first()->metadata)->toMatchArray(['subject_tenant_id' => $tenant->id, 'first_administrator' => true]);
});

it('refuses a second send within the throttle window, a tenant user, a suspended tenant and an accepted invitation', function () {
    Notification::fake();
    $tenant = $this->provision->handle(['name' => 'Initrode', 'slug' => 'initrode'], ['name' => 'Ian Owner', 'email' => 'owner@initrode.test'], 'New customer', $this->operator);
    expect($this->administrator->needsInvitation($tenant))->toBeFalse();

    expect(fn () => $this->administrator->send($tenant, $this->operator, 'Customer lost the e-mail'))->toThrow(RuntimeException::class, 'moments ago');
    $this->travel(2)->minutes();
    actAsTenant($tenant);
    $tenantAdmin = tenantUser($tenant, ['*']);
    actAsTenant(null);
    expect(fn () => $this->administrator->send($tenant, $tenantAdmin, 'Customer lost the e-mail'))->toThrow(RuntimeException::class, 'Only platform operators');

    app(TenantSuspensions::class)->suspend($tenant, 'Investigation', $this->operator);
    expect(fn () => $this->administrator->send($tenant->fresh(), $this->operator, 'Customer lost the e-mail'))->toThrow(RuntimeException::class, 'suspended');
    app(TenantSuspensions::class)->reactivate($tenant, 'Cleared', $this->operator);

    $this->administrator->send($tenant->fresh(), $this->operator, 'Customer lost the e-mail');
    User::query()->where('email', 'owner@initrode.test')->update(['status' => UserStatus::Active->value]);
    $this->travel(2)->minutes();
    expect(fn () => $this->administrator->send($tenant->fresh(), $this->operator, 'Again'))->toThrow(RuntimeException::class, 'no first administrator waiting');
    expect(UserInvitation::query()->withoutTenancy()->count())->toBe(2);
});

it('lets only a platform operator provision, with a reason, and keeps personal data off the platform chain', function () {
    Notification::fake();
    $other = $this->provision->handle(['name' => 'Existing', 'slug' => 'existing']);
    actAsTenant($other);
    $tenantAdmin = tenantUser($other, ['*']);
    actAsTenant(null);

    expect(fn () => $this->provision->handle(['name' => 'Rogue', 'slug' => 'rogue'], null, 'Self-service', $tenantAdmin))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $this->provision->handle(['name' => 'Rogue', 'slug' => 'rogue'], null, null, $this->operator))->toThrow(RuntimeException::class, 'A reason is required')
        ->and(Tenant::query()->where('slug', 'rogue')->exists())->toBeFalse();

    $this->provision->handle(['name' => 'Wonka', 'slug' => 'wonka'], ['name' => 'Will Owner', 'email' => 'owner@wonka.test'], 'Signed order form', $this->operator);
    $event = platformEvents('TENANT_PROVISIONED')->where('metadata.subject_tenant', 'wonka')->sole();
    expect($event->actor_id)->toBe($this->operator->id)->and($event->reason)->toBe('Signed order form')
        ->and(json_encode($event->metadata))->not->toContain('owner@wonka.test');
});

it('records a tenant edit on both chains with an operator and a reason, never touching the status', function () {
    $tenant = $this->provision->handle(['name' => 'Hooli', 'slug' => 'hooli']);
    $profiles = app(TenantProfiles::class);
    actAsTenant($tenant);
    $tenantAdmin = tenantUser($tenant, ['*']);
    actAsTenant(null);

    expect(fn () => $profiles->update($tenant, ['name' => 'Hooli XYZ'], $tenantAdmin, 'Rebrand'))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $profiles->update($tenant, ['name' => 'Hooli XYZ'], $this->operator, ''))->toThrow(RuntimeException::class, 'A reason is required')
        ->and(fn () => $profiles->update($tenant, ['tier' => 'platinum'], $this->operator, 'Upgrade'))->toThrow(InvalidArgumentException::class);

    $profiles->update($tenant, ['name' => 'Hooli XYZ', 'status' => 'suspended', 'suspended_from' => 'trial'], $this->operator, 'Rebrand');

    expect($tenant->fresh()->name)->toBe('Hooli XYZ')->and($tenant->fresh()->status->value)->toBe('active')->and($tenant->fresh()->suspended_from)->toBeNull();
    $events = AuditEvent::query()->withoutTenancy()->where('action', 'TENANT_PROFILE_CHANGED')->get();
    expect($events->pluck('tenant_id')->sort()->values()->all())->toBe([null, $tenant->id])
        ->and($events->every(fn ($e) => $e->reason === 'Rebrand'))->toBeTrue();
});

it('offers Send invitation on the Tenants list only while the first administrator has not accepted', function () {
    breakMail();
    $tenant = $this->provision->handle(['name' => 'Acme Ops', 'slug' => 'acme-ops'], ['name' => 'Ana Owner', 'email' => 'owner@acme-ops.test'], 'New customer', $this->operator);
    $this->actingAs($this->operator);

    Livewire::test(ListTenants::class)->assertTableActionVisible('sendInvitation', $tenant);
    User::query()->where('email', 'owner@acme-ops.test')->update(['status' => UserStatus::Active->value]);
    Livewire::test(ListTenants::class)->assertTableActionHidden('sendInvitation', $tenant);
});

it('warns on the create page when the tenant was created but the invitation was not sent', function () {
    breakMail();
    $this->actingAs($this->operator);

    Livewire::test(CreateTenant::class)
        ->fillForm(['name' => 'Soylent', 'slug' => 'soylent', 'tier' => 'shared', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata', 'locale' => 'en', 'currency' => 'INR',
            'admin_name' => 'Sol Owner', 'admin_email' => 'owner@soylent.test', 'audit_reason' => 'New customer'])
        ->call('create')->assertHasNoFormErrors()->assertNotified('Tenant created, but the invitation e-mail was not sent');

    expect(Tenant::query()->where('slug', 'soylent')->exists())->toBeTrue();
});

it('requires a reason on the create form', function () {
    $this->actingAs($this->operator);

    Livewire::test(CreateTenant::class)
        ->fillForm(['name' => 'Tyrell', 'slug' => 'tyrell', 'tier' => 'shared', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata', 'locale' => 'en', 'currency' => 'INR',
            'admin_name' => 'Tia Owner', 'admin_email' => 'owner@tyrell.test'])
        ->call('create')->assertHasFormErrors(['audit_reason' => 'required']);
    expect(Tenant::query()->where('slug', 'tyrell')->exists())->toBeFalse();
});
