<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\InvitationLink;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\PlatformTenantAccess;
use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\TenantResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| SaaS.2 §10: tier, data region and trial end entered at creation are persisted (they were silently dropped),
| validated, audited, and remain metadata only. The status changes only through Suspend / Reactivate.
*/

beforeEach(function () {
    app(PermissionRegistry::class)->sync();
    $this->provision = app(ProvisionTenantAction::class);
});

it('keeps the tier, data region and trial end given at provisioning', function () {
    $tenant = $this->provision->handle(['name' => 'Initech', 'slug' => 'initech', 'tier' => 'dedicated', 'region' => 'eu', 'trial_ends_at' => '2026-11-30 18:30:00'], reason: 'Pilot customer');

    $stored = Tenant::query()->findOrFail($tenant->id);
    expect($stored->tier)->toBe('dedicated')
        ->and($stored->region)->toBe('eu')
        ->and($stored->trial_ends_at?->toDateTimeString())->toBe('2026-11-30 18:30:00');

    $provisioned = AuditEvent::query()->withoutTenancy()->where('action', 'TENANT_PROVISIONED')->sole();
    expect($provisioned->tenant_id)->toBe($tenant->id)
        ->and($provisioned->metadata)->toMatchArray(['tier' => 'dedicated', 'region' => 'eu', 'trial_ends_at' => '2026-11-30T18:30:00+00:00']);
});

it('defaults to the shared tier with no region and no trial end', function () {
    $tenant = $this->provision->handle(['name' => 'Umbrella', 'slug' => 'umbrella']);

    expect($tenant->fresh()->tier)->toBe('shared')->and($tenant->fresh()->region)->toBeNull()->and($tenant->fresh()->trial_ends_at)->toBeNull();
});

it('refuses an unknown tier or region instead of storing it', function (array $data, string $message) {
    expect(fn () => $this->provision->handle(['name' => 'Bad', 'slug' => 'bad'] + $data))->toThrow(InvalidArgumentException::class, $message);
    expect(Tenant::query()->where('slug', 'bad')->exists())->toBeFalse();
})->with([
    'tier' => [['tier' => 'enterprise'], 'Unknown tenant tier'],
    'region' => [['region' => 'mars'], 'Unknown data region'],
]);

it('never changes the status from the edit form, and audits metadata edits in the tenant\'s own chain', function () {
    $tenant = $this->provision->handle(['name' => 'Hooli', 'slug' => 'hooli']);
    $entered = $this->provision->handle(['name' => 'Pied Piper', 'slug' => 'pied-piper']);
    $operator = platformAdmin();
    $this->actingAs($operator);
    // The operator is inside another tenant while editing this tenant's record.
    app(PlatformTenantAccess::class)->enter($operator, $entered, 'Unrelated support work', null, session()->driver());
    actAsTenant($entered);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['status' => TenantStatus::Suspended->value, 'region' => 'in', 'audit_reason' => 'Residency confirmed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active)->and($tenant->fresh()->region)->toBe('in');
    $update = AuditEvent::query()->withoutTenancy()->where('action', 'UPDATE')->where('entity_type', Tenant::class)->where('entity_id', (string) $tenant->id)->sole();
    expect($update->tenant_id)->toBe($tenant->id)->and($update->reason)->toBe('Residency confirmed');
});

it('keeps tenant users out of tenant records, by URL and by policy', function () {
    $tenant = $this->provision->handle(['name' => 'Vandelay', 'slug' => 'vandelay']);
    actAsTenant($tenant);
    $admin = tenantUser($tenant, ['*']);
    $this->actingAs($admin);

    $this->get(TenantResource::getUrl('edit', ['record' => $tenant]))->assertForbidden();
    expect($admin->can('update', $tenant))->toBeFalse()->and($admin->can('tenant.update'))->toBeFalse();
});

it('creates a tenant from the platform form with its metadata, and invites the first administrator', function () {
    Notification::fake();
    $this->actingAs(platformAdmin());

    Livewire::test(CreateTenant::class)
        ->assertFormFieldDoesNotExist('admin_password')
        ->fillForm(['name' => 'Soylent', 'slug' => 'soylent', 'tier' => 'dedicated', 'region' => 'in', 'trial_ends_at' => '2026-12-31 12:00:00',
            'country_code' => 'IN', 'timezone' => 'Asia/Kolkata', 'locale' => 'en', 'currency' => 'INR',
            'admin_name' => 'Sol Owner', 'admin_email' => 'owner@soylent.test', 'audit_reason' => 'New customer'])
        ->call('create')->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'soylent')->sole();
    $owner = User::query()->where('email', 'owner@soylent.test')->sole();
    expect($tenant->tier)->toBe('dedicated')->and($tenant->region)->toBe('in')->and($tenant->trial_ends_at->toDateTimeString())->toBe('2026-12-31 12:00:00')
        ->and($owner->status)->toBe(UserStatus::Invited)
        ->and($owner->tenant_id)->toBe($tenant->id)
        ->and(app(TenantContext::class)->runAs($tenant, fn () => $owner->roleSlugs()))->toBe(['tenant-super-admin']);
    Notification::assertSentTo($owner, InvitationLink::class);
});
