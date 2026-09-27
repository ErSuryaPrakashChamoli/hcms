<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Analytics\Services\AnalyticsDefaults;
use App\Domain\Assets\Services\AssetDefaults;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Letters\Services\LetterDefaults;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\WorkMode;
use App\Domain\Payroll\Services\PayrollDefaults;
use App\Domain\Performance\Services\PerformanceDefaults;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Models\TenantFeature;
use App\Domain\Platform\Models\TenantSetting;
use App\Domain\ServiceDesk\Services\ServiceDeskDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a tenant with its system roles, default features, default settings and first admin.
 */
final class ProvisionTenantAction
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PermissionRegistry $permissions,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{name: string, slug?: string, status?: TenantStatus|string, country_code?: string, timezone?: string, locale?: string, currency?: string}  $tenantData
     * @param  array{name: string, email: string, password: string}|null  $adminData
     */
    public function handle(array $tenantData, ?array $adminData = null, ?string $reason = null): Tenant
    {
        return DB::transaction(function () use ($tenantData, $adminData, $reason) {
            $tenant = $this->tenants->bypass(fn () => Tenant::create([
                'name' => $tenantData['name'],
                'slug' => $tenantData['slug'] ?? Str::slug($tenantData['name']),
                'status' => $tenantData['status'] ?? TenantStatus::Active,
                'country_code' => $tenantData['country_code'] ?? 'IN',
                'timezone' => $tenantData['timezone'] ?? 'Asia/Kolkata',
                'locale' => $tenantData['locale'] ?? 'en',
                'currency' => $tenantData['currency'] ?? 'INR',
            ]));

            return $this->tenants->runAs($tenant, function () use ($tenant, $adminData, $reason) {
                $roles = $this->seedSystemRoles();
                $this->seedFeatures();
                $this->seedSettings();
                $this->seedOrganisationDefaults();

                if ($adminData !== null) {
                    $admin = User::create([
                        'tenant_id' => $tenant->id,
                        'name' => $adminData['name'],
                        'email' => $adminData['email'],
                        'password' => $adminData['password'],
                        'status' => UserStatus::Active,
                    ]);

                    $admin->roles()->attach($roles['tenant-super-admin']);
                }

                $this->audit->record(
                    action: AuditAction::TenantProvisioned,
                    module: 'platform',
                    entity: $tenant,
                    reason: $reason,
                    metadata: ['admin_email' => $adminData['email'] ?? null],
                );

                return $tenant;
            });
        });
    }

    /** @return array<string, Role> */
    public function seedSystemRoles(): array
    {
        $roles = [];

        foreach (config('peopleos.roles', []) as $slug => $template) {
            $role = Role::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $template['name'], 'description' => $template['description'] ?? null, 'is_system' => true],
            );

            $role->permissions()->syncWithoutDetaching(
                $this->permissions->idsMatching($template['permissions'] ?? []),
            );

            $roles[$slug] = $role;
        }

        return $roles;
    }

    private function seedFeatures(): void
    {
        foreach (config('peopleos.features', []) as $feature => $definition) {
            TenantFeature::query()->firstOrCreate(['feature' => $feature], ['enabled' => (bool) $definition['enabled']]);
        }
    }

    /** Starting people-setup records (blueprint §12, §76). Tenants rename, retire or extend them. */
    public function seedOrganisationDefaults(): void
    {
        $sets = [
            'employment_types' => EmploymentType::class,
            'employee_categories' => EmployeeCategory::class,
            'work_modes' => WorkMode::class,
            'levels' => Level::class,
        ];

        foreach ($sets as $key => $model) {
            foreach (config("peopleos.organisation.defaults.{$key}", []) as $row) {
                $model::query()->firstOrCreate(['code' => $row['code']], $row);
            }
        }

        foreach (config('peopleos.documents.defaults', []) as $row) {
            DocumentType::query()->firstOrCreate(['code' => $row['code']], $row);
        }

        foreach (config('peopleos.leave.defaults', []) as $i => $row) {
            LeaveType::query()->firstOrCreate(['code' => $row['code']], $row + ['sort_order' => $i]);
        }

        app(PayrollDefaults::class)->seed();
        app(PerformanceDefaults::class)->seed();
        app(AssetDefaults::class)->seed();
        app(ServiceDeskDefaults::class)->seed();
        app(LetterDefaults::class)->seed();
        app(AnalyticsDefaults::class)->seed();
    }

    private function seedSettings(): void
    {
        foreach (config('peopleos.settings', []) as $key => $value) {
            if ($value === null) {
                continue;
            }

            TenantSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
