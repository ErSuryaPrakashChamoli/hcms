<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Analytics\Services\AnalyticsDefaults;
use App\Domain\Assets\Services\AssetDefaults;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Services\CompensationDefaults;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Engagement\Services\MoodDefaults;
use App\Domain\Expense\Services\ExpenseDefaults;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Identity\Services\RoleGrants;
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
use App\Domain\Platform\Services\TenantFirstAdministrator;
use App\Domain\Platform\Support\PlatformOperator;
use App\Domain\Recognition\Services\RecognitionDefaults;
use App\Domain\ServiceDesk\Services\ServiceDeskDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
     * @param  array{name: string, slug?: string, status?: TenantStatus|string, country_code?: string, timezone?: string, locale?: string, currency?: string, tier?: string, region?: string|null, trial_ends_at?: \DateTimeInterface|string|null}  $tenantData
     * @param  array{name: string, email: string, password?: string}|null  $adminData  SaaS.2: without a password (the
     *                                                                                 operator's form) the first administrator is invited by e-mail and chooses
     *                                                                                 their own; a password is accepted only from the seeder and tests
     * @param  User|null  $actor  SaaS.8: the platform operator provisioning (required on the web; a reason too). Without one,
     *                            only the console may provision (seeders, runbook commands).
     */
    public function handle(array $tenantData, ?array $adminData = null, ?string $reason = null, ?User $actor = null): Tenant
    {
        [$tenant, $invitee] = $this->provisionCore($tenantData, $adminData, $reason, $actor);

        // SaaS.8: the e-mail is sent after the commit; a failure no longer escapes a tenant that exists. It is recorded, and
        // the operator sends the invitation again from the Tenants list (TenantFirstAdministrator).
        if ($invitee !== null) {
            app(TenantFirstAdministrator::class)->inviteAfterProvisioning($tenant, $invitee, $actor);
        }

        return $tenant;
    }

    /**
     * SaaS.10: the transactional core of provisioning, without the invitation e-mail. A caller that runs it inside its
     * own transaction (self-service signup: tenant, owner and trial commit together) sends the invitation after its
     * own commit, never from inside a transaction that may still roll back.
     *
     * @param  string  $adminRole  the first administrator's role: Tenant Super Admin (operators) or Account Owner (signup)
     * @param  array<string, mixed>  $auditMetadata  added to both TENANT_PROVISIONED events (no personal data)
     * @return array{0: Tenant, 1: ?User} the tenant, and the invited first administrator (null when a password was set)
     */
    public function provisionCore(array $tenantData, ?array $adminData, ?string $reason, ?User $actor, string $adminRole = 'tenant-super-admin', array $auditMetadata = []): array
    {
        PlatformOperator::assert($actor, 'provision a tenant');
        if ($actor !== null) {
            $reason = PlatformOperator::reason($reason);
        }
        if (! in_array($adminRole, (array) config('peopleos.identity.first_administrator_roles', []), true)) {
            throw new InvalidArgumentException("A first administrator cannot be given the role [{$adminRole}].");
        }
        $metadata = $this->metadata($tenantData);
        // SaaS.9: a tenant starts in use; closed and deleted are reached only through offboarding.
        $status = $tenantData['status'] ?? TenantStatus::Active;
        $status = $status instanceof TenantStatus ? $status : TenantStatus::tryFrom((string) $status);
        if ($status === null || ! $status->operating()) {
            throw new InvalidArgumentException('A tenant is provisioned as trial or active.');
        }
        $tenantData['status'] = $status;
        $invitee = null;

        $tenant = DB::transaction(function () use ($tenantData, $adminData, $reason, $actor, $metadata, $adminRole, $auditMetadata, &$invitee) {
            $tenant = $this->tenants->bypass(fn () => Tenant::create([
                'name' => $tenantData['name'],
                'slug' => $tenantData['slug'] ?? Str::slug($tenantData['name']),
                'status' => $tenantData['status'] ?? TenantStatus::Active,
                'country_code' => $tenantData['country_code'] ?? 'IN',
                'timezone' => $tenantData['timezone'] ?? 'Asia/Kolkata',
                'locale' => $tenantData['locale'] ?? 'en',
                'currency' => $tenantData['currency'] ?? 'INR',
            ] + $metadata));

            return $this->tenants->runAs($tenant, function () use ($tenant, $adminData, $reason, $actor, $adminRole, $auditMetadata, &$invitee) {
                $roles = $this->seedSystemRoles();
                $this->seedFeatures();
                $this->seedSettings();
                $this->seedOrganisationDefaults();

                if ($adminData !== null) {
                    $chosen = filled($adminData['password'] ?? null);
                    $admin = User::create([
                        'tenant_id' => $tenant->id,
                        'name' => $adminData['name'],
                        'email' => $adminData['email'],
                        'password' => $chosen ? $adminData['password'] : Str::random(64),
                        'status' => $chosen ? UserStatus::Active : UserStatus::Invited,
                    ]);
                    if ($chosen) {
                        $admin->forceFill(['email_verified_at' => now()])->save();
                    } else {
                        $invitee = $admin;
                    }

                    $admin->roles()->attach($roles[$adminRole]);
                }

                $this->audit->record(
                    action: AuditAction::TenantProvisioned,
                    module: 'platform',
                    entity: $tenant,
                    reason: $reason,
                    actor: $actor,
                    metadata: ['admin_email' => $adminData['email'] ?? null, 'tier' => $tenant->tier, 'region' => $tenant->region, 'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                        'first_administrator_role' => $adminData !== null ? $adminRole : null] + $auditMetadata,
                );
                // SaaS.8: provisioning is an operator action, so it is on the platform chain too (no personal data there).
                $this->audit->record(
                    action: AuditAction::TenantProvisioned,
                    module: 'platform',
                    reason: $reason,
                    entityLabel: "Tenant {$tenant->name}",
                    actor: $actor,
                    metadata: ['subject_tenant_id' => $tenant->id, 'subject_tenant' => $tenant->slug, 'status' => $tenant->status->value, 'tier' => $tenant->tier, 'region' => $tenant->region, 'first_administrator' => $adminData !== null,
                        'first_administrator_role' => $adminData !== null ? $adminRole : null] + $auditMetadata,
                    platform: true,
                );

                return $tenant;
            });
        });

        return [$tenant, $invitee];
    }

    /**
     * SaaS.2: the tier, data region and trial end entered at creation are kept (they were silently dropped).
     * They are tenant metadata only; nothing enforces them yet. Unknown values are refused, not stored.
     *
     * @return array{tier: string, region: ?string, trial_ends_at: ?Carbon}
     */
    private function metadata(array $tenantData): array
    {
        $tier = (string) ($tenantData['tier'] ?? 'shared');
        if (! array_key_exists($tier, config('peopleos.enterprise.tiers', []))) {
            throw new InvalidArgumentException("Unknown tenant tier [{$tier}].");
        }
        $region = filled($tenantData['region'] ?? null) ? (string) $tenantData['region'] : null;
        if ($region !== null && ! array_key_exists($region, config('peopleos.enterprise.regions', []))) {
            throw new InvalidArgumentException("Unknown data region [{$region}].");
        }
        $trialEnds = filled($tenantData['trial_ends_at'] ?? null) ? Carbon::parse($tenantData['trial_ends_at']) : null;

        return ['tier' => $tier, 'region' => $region, 'trial_ends_at' => $trialEnds];
    }

    /** @return array<string, Role> */
    /**
     * Creates the system roles of the current tenant, or tops them up (`peopleos:sync-permissions`, on every deploy).
     *
     * SaaS.12: a top-up gives a role only the template keys it has never been given (`template_keys`), so a
     * permission the tenant removed stays removed. Keys a release introduces are given and audited
     * (`ROLE_CHANGED`, no actor). A role recorded before this tracking takes its current template as the baseline,
     * granting nothing: every earlier deploy had already given it the whole template.
     */
    public function seedSystemRoles(): array
    {
        $roles = [];

        foreach (config('peopleos.roles', []) as $slug => $template) {
            $role = Role::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $template['name'], 'description' => $template['description'] ?? null, 'is_system' => true],
            );
            // HROne parity SV-7: a role the tenant made itself that happens to carry a template's slug (e.g. its own
            // "external" role, added before the External user template existed) is never given template permissions.
            if (! $role->is_system) {
                continue;
            }
            $templateKeys = collect($this->permissions->keysMatching($template['permissions'] ?? []))->sort()->values()->all();
            $given = $role->wasRecentlyCreated ? [] : ($role->template_keys ?? $templateKeys);
            $introduced = array_values(array_diff($templateKeys, $given));
            $before = $introduced === [] || $role->wasRecentlyCreated ? null : app(RoleGrants::class)->permissionKeysOf($role);

            // HROne parity TX-2: a key split out of a broader one (`peopleos.permission_splits`) goes only to a role that
            // holds the broader key (or is given it now), so a role the tenant took the broader key from gains nothing.
            if ($before !== null) {
                $splits = (array) config('peopleos.permission_splits', []);
                $introduced = array_values(array_filter($introduced, fn (string $key) => ! isset($splits[$key])
                    || in_array($splits[$key], $before, true) || in_array($splits[$key], $introduced, true)));
            }

            if ($introduced !== []) {
                $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', $introduced)->pluck('id'));

                if ($before !== null) {
                    app(RoleGrants::class)->recordPermissionChange($role, $before, null, 'Permissions introduced by a release into this system role\'s template');
                }
            }

            if ($role->template_keys !== $templateKeys) {
                $role->forceFill(['template_keys' => $templateKeys])->save();
            }

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
        app(CompensationDefaults::class)->seed();
        app(PerformanceDefaults::class)->seed();
        app(AssetDefaults::class)->seed();
        app(ExpenseDefaults::class)->seed();
        app(ServiceDeskDefaults::class)->seed();
        app(LetterDefaults::class)->seed();
        app(AnalyticsDefaults::class)->seed();
        // HROne setup parity HP-9c: HROne's nine system badges.
        app(RecognitionDefaults::class)->seed();
        // HROne setup parity HP-9f: HROne's fourteen mood bot reasons (the mood bot itself stays off).
        app(MoodDefaults::class)->seed();
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
