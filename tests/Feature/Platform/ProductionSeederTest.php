<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\InvitationLink;
use App\Domain\Identity\Notifications\PasswordResetLink;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Production\FirstTenantSeeder;
use Database\Seeders\Production\PlatformOperatorSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
| ProductionSeeder: the production baseline that `php artisan db:seed --force` runs on every deploy. Idempotent, no demo
| data, no password; the first operator and tenant only when configured.
*/

/** Runs a seeder as the production deploy does: `db:seed --force` with the production environment. */
function seedAsProduction(?string $class = null): void
{
    app()['env'] = 'production';
    try {
        test()->artisan('db:seed', array_filter(['--class' => $class, '--force' => true]))->assertSuccessful();
    } finally {
        app()['env'] = 'testing';
    }
}

it('seeds the catalogues through db:seed in production, idempotently, with no account or tenant', function () {
    seedAsProduction();

    $permissions = Permission::query()->count();
    $rules = ComplianceRule::query()->count();
    $layouts = StatutoryExportLayout::query()->count();
    expect($permissions)->toBe(count(app(PermissionRegistry::class)->keysMatching(['*'])))
        ->and($rules)->toBeGreaterThan(0)
        ->and($layouts)->toBeGreaterThan(0)
        // A pack never verifies a rule: that stays two platform administrators' act.
        ->and(ComplianceRule::query()->where('verification_status', ComplianceRule::VERIFIED)->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(Tenant::query()->count())->toBe(0);

    seedAsProduction();

    expect(Permission::query()->count())->toBe($permissions)
        ->and(ComplianceRule::query()->count())->toBe($rules)
        ->and(StatutoryExportLayout::query()->count())->toBe($layouts)
        ->and(User::query()->count())->toBe(0);
});

it('creates the first platform operator once, with no known password, and e-mails a reset link', function () {
    Notification::fake();
    config(['peopleos.bootstrap.operator' => ['email' => ' First.Operator@Markedge.in ', 'name' => 'First Operator']]);

    seedAsProduction(ProductionSeeder::class);
    seedAsProduction(ProductionSeeder::class);

    $operator = User::query()->sole();
    $audit = app(TenantContext::class)->bypass(fn () => AuditEvent::query()->get());
    expect($operator->email)->toBe('first.operator@markedge.in')
        ->and($operator->isPlatformAdmin())->toBeTrue()
        ->and($operator->tenant_id)->toBeNull()
        ->and($operator->status)->toBe(UserStatus::Active)
        ->and($operator->hasMfaEnabled())->toBeFalse() // enrolled at the first sign-in
        ->and(Hash::check('password', $operator->password))->toBeFalse()
        ->and(DB::table('password_reset_tokens')->where('email', $operator->email)->count())->toBe(1)
        ->and($audit->where('action', AuditAction::Create)->where('entity_type', $operator->getMorphClass())->sole()->reason)->toBe(PlatformOperatorSeeder::REASON)
        ->and($audit->where('action', AuditAction::PasswordResetRequested)->count())->toBe(1);
    // The second run changed nothing and sent nothing.
    Notification::assertSentToTimes($operator, PasswordResetLink::class, 1);
});

it('never promotes a tenant user to platform operator', function () {
    $tenant = provisionTenant();
    $user = tenantUser($tenant, attributes: ['email' => 'hr.lead@acme-industries.in']);
    config(['peopleos.bootstrap.operator.email' => 'hr.lead@acme-industries.in']);

    expect(fn () => seedAsProduction(PlatformOperatorSeeder::class))->toThrow(RuntimeException::class, 'never promoted');

    expect($user->fresh()->is_platform_admin)->toBeFalse()
        ->and($user->fresh()->tenant_id)->toBe($tenant->id);
});

it('refuses a reserved, undeliverable operator address in production only', function () {
    Notification::fake();
    config(['peopleos.bootstrap.operator.email' => 'ops@markedge.local']);

    expect(fn () => seedAsProduction(PlatformOperatorSeeder::class))->toThrow(RuntimeException::class, 'reserved, undeliverable domain');
    expect(User::query()->count())->toBe(0);

    $this->seed(PlatformOperatorSeeder::class);
    expect(User::query()->sole()->isPlatformAdmin())->toBeTrue();
});

it('provisions the first tenant once and invites its administrator', function () {
    Notification::fake();
    config(['peopleos.bootstrap.tenant' => [
        'name' => 'Acme Industries', 'slug' => null, 'country_code' => 'in', 'timezone' => 'Asia/Kolkata', 'currency' => 'inr',
        'admin_name' => 'Asha Menon', 'admin_email' => 'asha.menon@acme-industries.in',
    ]]);

    seedAsProduction();
    seedAsProduction();

    $tenant = app(TenantContext::class)->bypass(fn () => Tenant::query()->sole());
    expect($tenant->slug)->toBe('acme-industries')
        ->and($tenant->country_code)->toBe('IN')
        ->and($tenant->currency)->toBe('INR');

    app(TenantContext::class)->runAs($tenant, function () {
        $admin = User::query()->where('email', 'asha.menon@acme-industries.in')->sole();
        expect($admin->status)->toBe(UserStatus::Invited)
            ->and($admin->roles()->pluck('slug')->all())->toBe(['tenant-super-admin'])
            ->and(Role::query()->where('is_system', true)->count())->toBe(count(config('peopleos.roles')));
        Notification::assertSentToTimes($admin, InvitationLink::class, 1);
    });
});

it('refuses a reserved tenant address and an address already in use', function () {
    config(['peopleos.bootstrap.tenant' => ['name' => 'Admin', 'admin_name' => 'A', 'admin_email' => 'a@acme-industries.in']]);
    expect(fn () => seedAsProduction(FirstTenantSeeder::class))->toThrow(RuntimeException::class, 'is reserved');

    platformAdmin()->forceFill(['email' => 'taken@acme-industries.in'])->save();
    config(['peopleos.bootstrap.tenant' => ['name' => 'Acme', 'admin_name' => 'A', 'admin_email' => 'taken@acme-industries.in']]);
    expect(fn () => seedAsProduction(FirstTenantSeeder::class))->toThrow(RuntimeException::class, 'already belongs to an account');

    expect(Tenant::query()->count())->toBe(0);
});
