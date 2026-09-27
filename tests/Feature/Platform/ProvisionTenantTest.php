<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Models\TenantFeature;
use App\Domain\Platform\Models\TenantSetting;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;

it('provisions a tenant with roles, features, settings and a super admin', function () {
    app(PermissionRegistry::class)->sync();

    $tenant = app(ProvisionTenantAction::class)->handle(
        ['name' => 'Globex', 'slug' => 'globex'],
        ['name' => 'Hank', 'email' => 'hank@globex.test', 'password' => 'a-very-long-password'],
        reason: 'New customer',
    );

    actAsTenant($tenant);

    $admin = User::query()->where('email', 'hank@globex.test')->first();

    expect($admin->tenant_id)->toBe($tenant->id)
        ->and($admin->roleSlugs())->toBe(['tenant-super-admin'])
        ->and($admin->hasPermission('company.delete'))->toBeTrue()
        ->and(TenantFeature::query()->count())->toBe(count(config('peopleos.features')))
        ->and(TenantSetting::query()->count())->toBe(count(array_filter(config('peopleos.settings'), fn ($v) => $v !== null)))
        ->and(AuditEvent::query()->where('action', 'TENANT_PROVISIONED')->value('reason'))->toBe('New customer')
        ->and(app(TenantContext::class)->id())->toBe($tenant->id);
});

it('rolls back everything if provisioning fails part-way', function () {
    app(PermissionRegistry::class)->sync();
    User::factory()->create(['email' => 'taken@example.test']);

    expect(fn () => app(ProvisionTenantAction::class)->handle(
        ['name' => 'Broken'],
        ['name' => 'Dup', 'email' => 'taken@example.test', 'password' => 'a-very-long-password'],
    ))->toThrow(QueryException::class);

    expect(Tenant::query()->where('slug', 'broken')->exists())->toBeFalse();
});

it('reads settings with platform defaults underneath and caches per tenant', function () {
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $settings = app(SettingsRepository::class);

    expect($settings->get('locale.date_format'))->toBe('d M Y')
        ->and($settings->get('branding.display_name'))->toBeNull()
        ->and($settings->get('nonexistent', 'fallback'))->toBe('fallback');

    $settings->set('branding.display_name', 'Acme People', reason: 'Branding');

    expect($settings->get('branding.display_name'))->toBe('Acme People')
        ->and(AuditEvent::query()->where('module', 'platform')->where('entity_label', 'branding.display_name')->latest('occurred_at')->value('reason'))->toBe('Branding');

    $other = provisionTenant('Other');
    actAsTenant($other);
    expect($settings->get('branding.display_name'))->toBeNull();
});

it('toggles feature flags per tenant', function () {
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $features = app(FeatureFlags::class);

    expect($features->enabled('organisation.designer'))->toBeFalse()
        ->and($features->enabled('audit.sensitive_access'))->toBeTrue()
        ->and($features->enabled('unknown.flag'))->toBeFalse();

    $features->set('organisation.designer', true);

    expect($features->enabled('organisation.designer'))->toBeTrue();

    $other = provisionTenant('Other');
    actAsTenant($other);
    expect($features->enabled('organisation.designer'))->toBeFalse();
});

it('propagates the tenant into queued jobs through Context', function () {
    $tenant = provisionTenant();
    actAsTenant($tenant);

    $seen = null;
    $job = new class($seen) implements ShouldQueue
    {
        use Queueable;

        public static ?int $tenantId = null;

        public function __construct(public $unused) {}

        public function handle(): void
        {
            self::$tenantId = app(TenantContext::class)->id();
        }
    };

    // Reset the context between dispatch and execution, as a real worker would.
    $dehydrated = Context::dehydrate();
    actAsTenant(null);
    Context::hydrate($dehydrated);

    expect(app(TenantContext::class)->id())->toBe($tenant->id);
});
