<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\PlatformTenantAccess;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Livewire\Livewire;

/*
| SaaS.2 §7: a platform operator enters a tenant only through a reasoned, time-boxed, audited access, and
| stays a platform identity throughout.
*/

beforeEach(function () {
    $this->travelTo('2026-10-21 09:00:00');
    $this->tenant = provisionTenant('Alpha');
    $this->other = provisionTenant('Beta');
    actAsTenant($this->tenant);
    Company::factory()->create(['name' => 'Alpha Co']);
    actAsTenant(null);
    $this->operator = platformAdmin();
    $this->access = app(PlatformTenantAccess::class);
    $this->actingAs($this->operator);
});

function platformAudit(string $action): Collection
{
    return AuditEvent::query()->withoutTenancy()->where('action', $action)->orderBy('id')->get();
}

it('requires a reason before an operator can enter a tenant', function () {
    Livewire::test(ListTenants::class)
        ->callTableAction('enter', $this->tenant, data: ['reason' => 'look'])
        ->assertHasTableActionErrors(['reason' => 'min']);
    expect(session()->has(PlatformTenantAccess::SESSION_KEY))->toBeFalse()
        ->and(platformAudit('PLATFORM_ACCESS_STARTED'))->toHaveCount(0);

    expect(fn () => $this->access->enter($this->operator, $this->tenant, 'short', null, session()->driver()))->toThrow(RuntimeException::class);
});

it('records who entered which tenant, when, why, under what authority and in what mode, on both chains', function () {
    Livewire::test(ListTenants::class)
        ->callTableAction('enter', $this->tenant, data: ['reason' => 'Payroll run failed for the customer', 'reference' => 'SUP-4411'])
        ->assertHasNoTableActionErrors();

    $events = platformAudit('PLATFORM_ACCESS_STARTED');
    expect($events)->toHaveCount(2)
        ->and($events->pluck('tenant_id')->all())->toBe([null, $this->tenant->id]);
    foreach ($events as $event) {
        expect($event->actor_id)->toBe($this->operator->id)
            ->and($event->reason)->toBe('Payroll run failed for the customer')
            ->and($event->approval_reference)->toBe('SUP-4411')
            ->and($event->metadata['authority'])->toBe('platform_operator')
            ->and($event->metadata['mode'])->toBe('full_support')
            ->and($event->metadata['subject_tenant_id'])->toBe($this->tenant->id)
            ->and($event->metadata['expires_at'])->toBe('2026-10-21T10:00:00+00:00');
    }
});

it('links everything the operator does inside the tenant to the access, and records the exit with its duration', function () {
    $grant = $this->access->enter($this->operator, $this->tenant, 'Investigating a data issue', null, session()->driver());
    $this->get(CompanyResource::getUrl('index'))->assertOk()->assertSee('Alpha Co');
    expect(Context::get('platform.access_id'))->toBe($grant['id']);

    // A change made in that request context is audited in the tenant's chain, by the operator, linked to the access.
    $this->travel(12)->minutes();
    app(TenantContext::class)->runAs($this->tenant, fn () => Company::query()->where('name', 'Alpha Co')->first()->update(['name' => 'Alpha Co Ltd']));
    $change = AuditEvent::query()->withoutTenancy()->where('action', 'UPDATE')->where('entity_type', Company::class)->latest('id')->first();
    expect($change->tenant_id)->toBe($this->tenant->id)
        ->and($change->actor_id)->toBe($this->operator->id)
        ->and($change->metadata['platform_access_id'])->toBe($grant['id']);

    $this->post(route('admin.exit-tenant'))->assertRedirect();
    $ended = platformAudit('PLATFORM_ACCESS_ENDED');
    expect($ended)->toHaveCount(2)
        ->and($ended->pluck('tenant_id')->all())->toBe([null, $this->tenant->id])
        ->and($ended->first()->metadata['cause'])->toBe('exit')
        ->and($ended->first()->metadata['duration_seconds'])->toBe(720)
        ->and($ended->first()->metadata['platform_access_id'])->toBe($grant['id']);
});

it('ends the access on its own when the time box runs out, and on sign-out', function () {
    $this->access->enter($this->operator, $this->tenant, 'Checking a support request', null, session()->driver());
    $this->get(CompanyResource::getUrl('index'))->assertSee('Alpha Co');

    $this->travel(61)->minutes();
    actAsTenant(null);
    $this->get(CompanyResource::getUrl('index'))->assertOk()->assertDontSee('Alpha Co');
    expect(platformAudit('PLATFORM_ACCESS_ENDED')->first()->metadata['cause'])->toBe('expired')
        ->and(platformAudit('PLATFORM_ACCESS_ENDED')->first()->metadata['duration_seconds'])->toBe(3600)
        ->and(session()->has(PlatformTenantAccess::SESSION_KEY))->toBeFalse();

    $this->access->enter($this->operator, $this->other, 'Second support request', null, session()->driver());
    auth()->logout();
    expect(platformAudit('PLATFORM_ACCESS_ENDED')->last()->metadata['cause'])->toBe('sign_out')
        ->and(platformAudit('PLATFORM_ACCESS_ENDED')->last()->tenant_id)->toBe($this->other->id);
});

it('keeps the operator a platform identity: no tenant user, employee or role is created', function () {
    $users = User::query()->count();
    $this->access->enter($this->operator, $this->tenant, 'Reviewing an import problem', null, session()->driver());
    $this->get(CompanyResource::getUrl('index'))->assertOk();

    expect(User::query()->count())->toBe($users)
        ->and($this->operator->fresh()->tenant_id)->toBeNull()
        ->and($this->operator->fresh()->roles()->count())->toBe(0)
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => Employee::query()->where('user_id', $this->operator->id)->exists()))->toBeFalse();
});

it('never lets a tenant user use an operator access, even with a forged grant in their session', function () {
    $tenantUser = tenantUser($this->tenant, ['company.view']);
    $forged = ['id' => 'forged', 'tenant_id' => $this->other->id, 'reason' => 'x', 'reference' => null, 'mode' => 'full_support', 'entered_at' => now()->toIso8601String(), 'expires_at' => now()->addHour()->toIso8601String()];

    actAsTenant(null);
    $this->withSession([PlatformTenantAccess::SESSION_KEY => $forged])->actingAs($tenantUser)->get(CompanyResource::getUrl('index'))->assertOk()->assertSee('Alpha Co');
    expect(app(TenantContext::class)->id())->toBe($this->tenant->id);
    $this->post(route('admin.exit-tenant'))->assertForbidden();
});
