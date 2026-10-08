<?php

namespace App\Domain\Entitlements\Enums;

/**
 * SaaS.3: the commercial capability catalogue. Code-owned, like the permission catalogue: a capability exists
 * only if the code can observe (and one day enforce) it, so nothing unenforceable can be sold.
 *
 * A capability answers "does this TENANT have this?", never "may this USER do this?" (that stays with
 * permissions, scopes, field security and policies). The mapping to permission-key prefixes and API scopes
 * records which existing authorisation each module would narrow once enforcement is approved; it changes no
 * authorisation today.
 *
 * Packaging (which capabilities form which plan, limit values, trials) is NOT decided here (SaaS.1 D-1, D-4,
 * D-13, D-14, D-16).
 */
enum Capability: string
{
    // The HCM core and every security control: never commercial (always NOT_APPLICABLE).
    case Core = 'core';

    // Modules.
    case Onboarding = 'onboarding';
    case Attendance = 'attendance';
    case Leave = 'leave';
    case Payroll = 'payroll';
    case Compensation = 'compensation';
    case Performance = 'performance';
    case Learning = 'learning';
    case Talent = 'talent';
    case Workforce = 'workforce';
    case Assets = 'assets';
    case ServiceDesk = 'service_desk';
    case Engagement = 'engagement';
    case Exit = 'exit';
    case Analytics = 'analytics';
    case Ai = 'ai';
    case Integrations = 'integrations';
    case EnterpriseIdentity = 'enterprise_identity';
    case Warehouse = 'warehouse';

    // Features (inside a module).
    case AiExternalModel = 'ai.external_model';
    case AnalyticsScheduledReports = 'analytics.scheduled_reports';
    case IntegrationsApi = 'integrations.api';
    case IntegrationsWebhooks = 'integrations.webhooks';

    // Limits (null = unlimited). Only active_employees.max has a measured usage in SaaS.3; the others define the
    // contract future metering will feed (SaaS.1 G-MET).
    case ActiveEmployeesMax = 'active_employees.max';
    case UsersMax = 'users.max';
    case AdminUsersMax = 'admin_users.max';
    case LegalEntitiesMax = 'legal_entities.max';
    case LocationsMax = 'locations.max';
    case StorageBytesMax = 'storage_bytes.max';
    case ApiRequestsMonthlyMax = 'api_requests_monthly.max';
    case AiRequestsMonthlyMax = 'ai_requests_monthly.max';

    public function type(): CapabilityType
    {
        return match ($this) {
            self::AiExternalModel, self::AnalyticsScheduledReports, self::IntegrationsApi, self::IntegrationsWebhooks => CapabilityType::Feature,
            self::ActiveEmployeesMax, self::UsersMax, self::AdminUsersMax, self::LegalEntitiesMax, self::LocationsMax,
            self::StorageBytesMax, self::ApiRequestsMonthlyMax, self::AiRequestsMonthlyMax => CapabilityType::Limit,
            default => CapabilityType::Module,
        };
    }

    /** The module a feature or limit belongs to (a module returns itself). */
    public function module(): self
    {
        return match ($this) {
            self::AiExternalModel, self::AiRequestsMonthlyMax => self::Ai,
            self::AnalyticsScheduledReports => self::Analytics,
            self::IntegrationsApi, self::IntegrationsWebhooks, self::ApiRequestsMonthlyMax => self::Integrations,
            self::ActiveEmployeesMax, self::UsersMax, self::AdminUsersMax, self::LegalEntitiesMax, self::LocationsMax, self::StorageBytesMax => self::Core,
            default => $this,
        };
    }

    public function enforcement(): EnforcementClass
    {
        return match ($this) {
            self::Core => EnforcementClass::NotCommercial,
            // Statutory or lifecycle-critical: never enforced without a separately approved rollout.
            self::Payroll, self::Onboarding, self::Exit, self::ActiveEmployeesMax => EnforcementClass::Protected,
            default => EnforcementClass::Eligible,
        };
    }

    public function commercial(): bool
    {
        return $this->enforcement() !== EnforcementClass::NotCommercial;
    }

    public function label(): string
    {
        return match ($this) {
            self::Core => 'HCM core and security',
            self::ServiceDesk => 'Service desk',
            self::EnterpriseIdentity => 'Enterprise identity (SSO, SCIM)',
            self::Warehouse => 'Data warehouse feed',
            self::Ai => 'AI assistants',
            self::AiExternalModel => 'AI: external language model',
            self::AnalyticsScheduledReports => 'Analytics: scheduled reports',
            self::IntegrationsApi => 'Integrations: REST API',
            self::IntegrationsWebhooks => 'Integrations: outbound webhooks',
            self::ActiveEmployeesMax => 'Active employees (maximum)',
            self::UsersMax => 'Active users (maximum)',
            self::AdminUsersMax => 'Administrator users (maximum)',
            self::LegalEntitiesMax => 'Legal entities (maximum)',
            self::LocationsMax => 'Locations (maximum)',
            self::StorageBytesMax => 'File storage (maximum bytes)',
            self::ApiRequestsMonthlyMax => 'API requests per month (maximum)',
            self::AiRequestsMonthlyMax => 'AI requests per month (maximum)',
            default => ucfirst($this->value),
        };
    }

    /** Unit of a limit; null for modules and features. */
    public function unit(): ?string
    {
        return match ($this) {
            self::ActiveEmployeesMax => 'employees',
            self::UsersMax, self::AdminUsersMax => 'users',
            self::LegalEntitiesMax => 'legal entities',
            self::LocationsMax => 'locations',
            self::StorageBytesMax => 'bytes',
            self::ApiRequestsMonthlyMax, self::AiRequestsMonthlyMax => 'requests per month',
            default => null,
        };
    }

    /**
     * SaaS.5: whether PeopleOS measures this limit's usage today. Only the active-employee count is measured
     * (BillableUnits, observed when someone becomes employed). Every other limit is a contract until metering exists
     * (SaaS.1 WS4): a finite value for it answers USAGE_UNAVAILABLE. A test keeps this equal to the observeLimit()
     * call sites.
     */
    public function measured(): bool
    {
        return $this === self::ActiveEmployeesMax;
    }

    /** SaaS.5: the smallest value a plan may give this limit (a protected limit is never 0 in a plan); null for non-limits. */
    public function minimumLimit(): ?int
    {
        if ($this->type() !== CapabilityType::Limit) {
            return null;
        }

        return $this->enforcement() === EnforcementClass::Protected ? 1 : 0;
    }

    /**
     * SaaS.5: whether this limit can be "not included": only a limit inside a commercial module (AI requests in `ai`,
     * API requests in `integrations`). Such a limit never outlives its module, as a feature never does. A limit of the
     * HCM core (employees, users, legal entities, locations, storage) is always applicable.
     */
    public function followsModule(): bool
    {
        return $this->type() === CapabilityType::Limit && $this->module()->commercial();
    }

    /**
     * Permission-key prefixes (the part before the first dot) this module would narrow if it were ever
     * enforced. Every prefix in the permission catalogue belongs to exactly one module (tested).
     *
     * @return list<string>
     */
    public function permissionPrefixes(): array
    {
        return match ($this) {
            self::Core => ['tenant', 'company', 'user', 'role', 'settings', 'features', 'audit', 'organisation', 'people_setup', 'custom_field', 'form',
                'policy', 'configuration', 'blueprint', 'workflow', 'task', 'document', 'legal_entity', 'establishment', 'security', 'currency', 'notification', 'employee'],
            self::Onboarding => ['onboarding', 'bgv'],
            self::Attendance => ['attendance'],
            self::Leave => ['leave'],
            self::Payroll => ['payroll', 'compliance'],
            self::Compensation => ['compensation'],
            self::Performance => ['performance'],
            self::Learning => ['learning', 'skills', 'development'],
            self::Talent => ['career', 'talent', 'succession'],
            self::Workforce => ['workforce'],
            self::Assets => ['asset'],
            self::ServiceDesk => ['servicedesk', 'grievance', 'kb'],
            self::Engagement => ['communication', 'engagement'],
            self::Exit => ['exit', 'letter', 'alumni'],
            self::Analytics => ['analytics'],
            self::Ai => ['ai'],
            self::Integrations => ['api_key', 'integration', 'webhook'],
            self::EnterpriseIdentity => ['sso'],
            self::Warehouse => ['warehouse'],
            default => [],
        };
    }

    /**
     * API scopes whose requests belong to this module. Every scope belongs to exactly one module (tested).
     *
     * @return list<string>
     */
    public function apiScopes(): array
    {
        return match ($this) {
            self::Core => ['employees.read', 'employees.write', 'employees.sensitive.read', 'organisation.read', 'documents.read', 'workflows.read'],
            self::Onboarding => ['rms.write', 'rms.read', 'bgv.write'],
            self::Attendance => ['attendance.read', 'attendance.write'],
            self::Leave => ['leave.read', 'leave.write'],
            self::Payroll => ['payroll.read', 'compliance.read'],
            self::Compensation => ['compensation.read', 'compensation.sensitive'],
            self::Performance => ['performance.read', 'performance.write'],
            self::Learning => ['learning.read', 'learning.write', 'learning.costs'],
            self::Talent => ['career.read', 'talent.read', 'succession.read'],
            self::Workforce => ['positions.read', 'workforce.read', 'workforce.costs'],
            self::Assets => ['assets.read'],
            self::ServiceDesk => ['servicedesk.read'],
            self::Engagement => ['engagement.read', 'communications.read'],
            self::Analytics => ['reports.run'],
            self::Integrations => ['webhooks.read', 'integrations.read', 'integrations.write'],
            self::EnterpriseIdentity => ['scim'],
            default => [],
        };
    }

    public static function forApiScope(string $scope): ?self
    {
        foreach (self::cases() as $capability) {
            if (in_array($scope, $capability->apiScopes(), true)) {
                return $capability;
            }
        }

        return null;
    }

    /** @return list<self> */
    public static function commercialCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => $c->commercial()));
    }
}
