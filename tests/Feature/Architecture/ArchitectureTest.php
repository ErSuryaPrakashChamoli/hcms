<?php

use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Attendance\Models\CountryRegion;
use App\Domain\Attendance\Models\HolidayLibraryEntry;
use App\Domain\Attendance\Models\HolidayLibraryList;
use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Audit\Models\AuditVerificationRun;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\ConfigurationVersion;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\SupplierProfile;
use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Compliance\Models\ComplianceEvidenceDocument;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Compliance\Models\ComplianceRuleParameter;
use App\Domain\Compliance\Models\ComplianceRuleVerification;
use App\Domain\Compliance\Models\ProfessionalTaxRuleVersion;
use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Employment\Models\Employee;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Models\Plan;
use App\Domain\Entitlements\Models\PlanEntitlement;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\ArticleRead;
use App\Domain\Learning\Models\LearningInstructor;
use App\Domain\Leave\Services\AttendanceLeaveDayResolver;
use App\Domain\Offboarding\Models\RetentionPolicy;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\People\Models\Person;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Tax\Models\TaxRule;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\Pages\PeopleEditRecord;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\Pages\PeopleManageRecords;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Phase 0.2 baseline protection: architecture invariants that must hold for every future change.
 | Allow-lists are deliberate and documented in docs/architecture/security-invariants.md.
 */

function domainModelClasses(): array
{
    return collect(glob(app_path('Domain/*/Models/*.php')))
        ->map(fn (string $path) => 'App\\'.str_replace('/', '\\', Str::of($path)->after(app_path().'/')->before('.php')->toString()))
        ->filter(fn (string $class) => class_exists($class) && ! (new ReflectionClass($class))->isAbstract())
        ->values()
        ->all();
}

function appFilesMatching(string $pattern): array
{
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && preg_match($pattern, file_get_contents($file->getPathname()))) {
            $hits[] = Str::after($file->getPathname(), base_path().'/');
        }
    }
    sort($hits);

    return $hits;
}

it('scopes every domain model to a tenant except the documented platform-level models', function () {
    $platformLevel = [
        Tenant::class,
        User::class,
        Permission::class,
        AuditEvent::class,
        AuditEventChange::class,
        ComplianceRule::class,
        ComplianceRuleVerification::class, // Phase 5: platform rule verification history
        ProfessionalTaxRuleVersion::class, // Phase 5: PT view of compliance_rules
        ComplianceEvidenceDocument::class, // Phase 6: platform rule evidence
        ComplianceRuleParameter::class,
        ComplianceRuleNotice::class,
        StatutoryExportLayout::class, // Phase 6.2: platform export layouts
        // SaaS.4: Markedge's commercial plan catalogue (no tenant). A tenant's plan assignment is tenant-scoped.
        Plan::class, PlanVersion::class, PlanEntitlement::class,
        // SaaS.7: Markedge's billing catalogue (markets, prices and their versions, selling entities, tax rules, invoice
        // number series) and verified payment-provider events (they arrive without a tenant; one is resolved from a verified
        // reference into resolved_tenant_id). A tenant's billing profile, terms, invoices and payments are tenant-scoped.
        BillingMarket::class, PlanPrice::class, PlanPriceVersion::class,
        SupplierProfile::class, InvoiceNumberSeries::class, TaxRule::class,
        PaymentProviderEvent::class,
        // SaaS.7 completion: maker-checker requests are Markedge's operators' records (price publication has no tenant);
        // subject_tenant_id names the tenant concerned, if any. Credit notes, refunds, periods, notices and TDS are tenant-scoped.
        FinancialApproval::class,
        // SaaS.7 configuration: Markedge policy and statutory parameter versions are platform configuration (no tenant).
        // A customer's negotiated prices are tenant-scoped.
        ConfigurationVersion::class,
        // SaaS.8: audit-chain verification results (one row per chain per run; subject_tenant_id names the chain verified).
        AuditVerificationRun::class,
        // SaaS.9: retention policies are platform configuration (per category and jurisdiction; no tenant).
        RetentionPolicy::class,
        // SaaS.10: a signup application exists before its tenant; provisioned_tenant_id names the tenant it became.
        SignupApplication::class,
        // Holiday library: the platform's holiday lists per country, region and year, and its region list per country
        // (no tenant). A tenant's copy of a holiday is a tenant-scoped Holiday.
        HolidayLibraryList::class, HolidayLibraryEntry::class, CountryRegion::class,
    ];

    $unscoped = collect(domainModelClasses())
        ->reject(fn (string $class) => in_array(BelongsToTenant::class, class_uses_recursive($class), true))
        ->reject(fn (string $class) => in_array($class, $platformLevel, true))
        ->values()->all();

    expect($unscoped)->toBe([]);
});

it('applies the access scope to every employee-linked model except the documented exceptions', function () {
    $exceptions = [
        Person::class,          // reached only through Employee
        ArticleRead::class,   // read receipts, not people data
        AnnouncementRead::class,
        Employee::class,    // scoped directly by AccessScope in AccessScopes
        LearningInstructor::class, // catalogue directory entry; the employee link only names the instructor
    ];

    $missing = collect(domainModelClasses())
        ->filter(fn (string $class) => method_exists($class, 'employee'))
        ->reject(fn (string $class) => in_array(ScopedByEmployee::class, class_uses_recursive($class), true))
        ->reject(fn (string $class) => in_array($class, $exceptions, true))
        ->values()->all();

    expect($missing)->toBe([]);
});

it('audits every domain model except the documented append-only or derived tables', function () {
    $appendOnlyOrDerived = [
        'Lifecycle\Models\EmployeeTimelineEntry', 'Lifecycle\Models\EmployeeLifecycleTransition', 'Onboarding\Models\OnboardingTask',
        'Payroll\Models\PayrollEntryLine', 'Compliance\Models\ComplianceRule', 'Knowledge\Models\ArticleVersion', 'Knowledge\Models\ArticleRead',
        'Leave\Models\LeaveBalance', 'Leave\Models\LeaveLedgerEntry', 'Notifications\Models\NotificationDelivery', 'Attendance\Models\AttendancePunch',
        'Audit\Models\AuditEventChange', 'Audit\Models\AuditEvent', 'Analytics\Models\ReportRun', 'Workflow\Models\WorkflowAction',
        'Workflow\Models\WorkflowTask', 'Workflow\Models\WorkflowInstance', 'Enterprise\Models\WebhookDelivery', 'Identity\Models\Permission',
        'Learning\Models\AssessmentAttempt', 'Learning\Models\TrainingSessionAttendee', 'Exit\Models\FinalSettlementLine', 'Ai\Models\AiInteraction',
        'Assets\Models\AssetMovement', 'Communication\Models\AnnouncementRead', 'Performance\Models\GoalCheckIn', 'Performance\Models\AppraisalRating',
        'ServiceDesk\Models\TicketComment', 'Compliance\Models\ComplianceRuleVerification',
        // Phase 5 statutory outputs: rows derived from finalized payroll or append-only records, audited
        // through the return's STATUTORY_OUTPUT_* events and the statutory_return_actions log.
        'Compliance\Models\StatutoryReturnAction', 'Compliance\Models\StatutorySnapshot', 'Compliance\Models\StatutoryReconciliation',
        'Compliance\Models\EpfReturnRun', 'Compliance\Models\EpfReturnEntry', 'Compliance\Models\EpfReturnRevision',
        'Compliance\Models\EsiReturnRun', 'Compliance\Models\EsiReturnEntry', 'Compliance\Models\ProfessionalTaxReturn',
        'Compliance\Models\ProfessionalTaxReturnEntry', 'Compliance\Models\LwfReturn', 'Compliance\Models\LwfReturnEntry',
        'Compliance\Models\ProfessionalTaxRuleVersion', 'Compliance\Models\TdsAnnualLedger', 'Compliance\Models\TdsQuarterlyReturn',
        'Compliance\Models\TdsQuarterlyReturnEntry', 'Compliance\Models\TdsCertificate',
        // Phase 6 platform evidence records: append-only, audited through AuditRecorder platform events.
        'Compliance\Models\ComplianceEvidenceDocument', 'Compliance\Models\ComplianceRuleParameter', 'Compliance\Models\ComplianceRuleNotice', 'Compliance\Models\StatutoryExportLayout',
        'Compliance\Models\ParallelPayrollLine', // compared values; reviews audited on the parallel run
        // Phase 7: calibration history is itself the append-only record (each change is also audited
        // on the appraisal); reminder logs are derived de-duplication rows.
        'Performance\Models\CalibrationAdjustment', 'Performance\Models\PerformanceReminderLog',
        // Phase 8: completions are the append-only learning record (finalization and corrections are
        // audited on the enrolment / correction events); reminder logs are derived de-duplication rows.
        'Learning\Models\LearningCompletion', 'Learning\Models\LearningReminderLog',
        // Phase 9 / 10 / 11: reminder logs are derived de-duplication rows.
        'Talent\Models\TalentReminderLog', 'Workforce\Models\WorkforceReminderLog', 'Compensation\Models\CompensationReminderLog',
        // Phase 12: request status history is append-only (each move is audited on the ticket);
        // reminder logs are derived de-duplication rows.
        'ServiceDesk\Models\TicketTransition', 'ServiceDesk\Models\ServiceDeskReminderLog',
        // Phase 13: the anonymity boundary. Automatic auditing stamps the authenticated user and the exact
        // time, which would link a respondent to their answers. Responses, answers, participations,
        // confidential identities and feedback are therefore audited explicitly (anonymous mode, no actor
        // and no response id) by their services. Recipients are derived delivery rows (the snapshot is
        // audited as AUDIENCE_USED); reminder logs are derived de-duplication rows.
        'Engagement\Models\SurveyResponse', 'Engagement\Models\SurveyAnswer', 'Engagement\Models\SurveyParticipation',
        'Engagement\Models\EngagementIdentity', 'Engagement\Models\EmployeeFeedback', 'Engagement\Models\EngagementReminderLog',
        'Communication\Models\CommunicationRecipient',
        // Phase 14: inbound integration events are an event log whose every state change is audited
        // explicitly (INTEGRATION_EVENT_*); automatic auditing would copy the payload into the trail.
        'Integration\Models\InboundEvent',
        // Phase 14: API idempotency keys are a short-lived replay cache (request fingerprint plus an
        // encrypted response); the domain action they guard is audited by its own service.
        'Integration\Models\ApiIdempotencyKey',
        // Experience Transformation: personal display preferences (density, lens, pins, recents) are not
        // business records; UX metrics are anonymous per-tenant daily counters, and automatic auditing
        // would stamp the user on them and undo the anonymity.
        'Experience\Models\ExperiencePreference', 'Experience\Models\UxMetric',
        // SaaS.2: invitations are audited explicitly (issued, accepted, revoked) by UserInvitations; automatic
        // auditing would copy the token hash into the trail.
        'Identity\Models\UserInvitation',
        // SaaS.3: entitlement configuration is audited explicitly by EntitlementConfiguration, on the tenant's chain
        // and the platform chain, with the reason, version and replaced rows (automatic auditing would record a bare
        // field diff on one chain). Shadow observations are aggregated observability, not business records.
        'Entitlements\Models\TenantEntitlementProfile', 'Entitlements\Models\TenantEntitlement',
        'Entitlements\Models\EntitlementOverride', 'Entitlements\Models\EntitlementShadowObservation',
        // SaaS.4: the plan catalogue is audited explicitly by PlanCatalog on the platform chain, and plan assignments by
        // EntitlementConfiguration on both chains, with the reason, the before and after values and the effective date.
        'Entitlements\Models\Plan', 'Entitlements\Models\PlanVersion', 'Entitlements\Models\PlanEntitlement',
        'Entitlements\Models\TenantPlanAssignment',
        // SaaS.6: subscriptions and their periods are audited explicitly by CommercialSubscriptions on the tenant and platform
        // chains, with the state and plan version before and after, the effective date and the trigger.
        'Subscriptions\Models\TenantSubscription', 'Subscriptions\Models\SubscriptionPeriod',
        // SaaS.7: billing, tax and payment records are audited explicitly by their services (BillingAudit: the platform chain,
        // and the tenant chain for tenant records) with the reason, before and after, effective date and correlation key.
        'Billing\Models\BillingMarket', 'Billing\Models\PlanPrice', 'Billing\Models\PlanPriceVersion', 'Billing\Models\SupplierProfile',
        'Billing\Models\InvoiceNumberSeries', 'Billing\Models\TenantBillingProfile', 'Billing\Models\SubscriptionBillingTerm', 'Billing\Models\Invoice',
        'Billing\Models\InvoiceLine', 'Billing\Models\InvoiceTaxLine', 'Tax\Models\TaxRule', 'Payments\Models\Payment', 'Payments\Models\PaymentProviderEvent',
        // SaaS.7 completion: billing periods, price notices, approvals, credit notes, TDS claims and refunds are audited
        // explicitly by their services (BillingAudit, both chains for tenant records) with maker, checker, reasons, before,
        // after and the correlation key; the rows themselves are immutable or move forward only.
        'Billing\Models\BillingPeriod', 'Billing\Models\PriceChangeNotice', 'Billing\Models\FinancialApproval', 'Billing\Models\CreditNote',
        'Billing\Models\InvoiceTdsClaim', 'Payments\Models\Refund',
        // SaaS.7 configuration: configuration versions and negotiated prices are audited explicitly by their services
        // (CommercialConfiguration, NegotiatedPrices: maker, checker, reason, before and after, effective date); published
        // versions are immutable.
        'Billing\Models\ConfigurationVersion', 'Billing\Models\NegotiatedPrice', 'Billing\Models\NegotiatedPriceVersion',
        // SaaS.8: deferred jobs are an operational queue (an encrypted serialized job, deleted when dispatched again); the
        // release is audited with its count on the reactivation (TENANT_REACTIVATED) or the operator's command.
        'Platform\Models\TenantDeferredJob',
        // SaaS.8: verification results are themselves the append-only record of the audit trail's integrity.
        'Audit\Models\AuditVerificationRun',
        // SaaS.9: the exit's records are audited explicitly by their services (OffboardingAudit: the platform chain, and the
        // tenant chain while it exists) with operator, reason, references and outcome; automatic auditing would copy export
        // passphrases and deletion plans into the trail.
        'Offboarding\Models\TenantOffboarding', 'Offboarding\Models\TenantExport', 'Offboarding\Models\LegalHold', 'Offboarding\Models\RetentionPolicy',
        'Offboarding\Models\TenantDeletion', 'Offboarding\Models\TenantDeletionStep',
        // SaaS.9 closure: contractual retention terms, audited by TenantRetentionTerms on both chains.
        'Offboarding\Models\TenantRetentionTerm',
        // SaaS.10: signup applications are audited explicitly by SignupAudit on the platform chain (reference, states,
        // codes; never the applicant's address, name or link), and on the tenant chain once it exists.
        'Signup\Models\SignupApplication',
        // Holiday library: audited explicitly by HolidayLibrary on the platform chain (upload, publish, withdraw, delete with
        // country, region, year and counts). A tenant's copy is an audited Holiday.
        'Attendance\Models\HolidayLibraryList', 'Attendance\Models\HolidayLibraryEntry',
        // The region list: audited explicitly by CountryRegions on the platform chain (add, rename, retire, restore, sync).
        'Attendance\Models\CountryRegion',
    ];
    $allowed = array_map(fn (string $c) => 'App\\Domain\\'.$c, $appendOnlyOrDerived);

    $unaudited = collect(domainModelClasses())
        ->reject(fn (string $class) => in_array(Auditable::class, class_uses_recursive($class), true))
        ->reject(fn (string $class) => in_array($class, $allowed, true))
        ->values()->all();

    expect($unaudited)->toBe([]);
});

it('bypasses tenant scoping only in the documented platform services', function () {
    $allowed = [
        'app/Domain/Audit/Services/AuditIntegrityVerifier.php',
        'app/Domain/Audit/Services/AuditRecorder.php',
        'app/Domain/Identity/Services/AccessScopes.php',
        'app/Domain/Integration/Services/ApiKeys.php',
        // SaaS.2: an invitation token is looked up before the invitee is signed in or any tenant is bound.
        'app/Domain/Identity/Services/UserInvitations.php',
        // SaaS.3: the platform operators' cross-tenant shadow summary (aggregated counts and keys only).
        'app/Domain/Entitlements/Services/EntitlementDiagnostics.php',
        // SaaS.6: the operators' cross-tenant subscription overview (tenant names and commercial states only) and the
        // platform-chain subscription audit trail.
        'app/Domain/Subscriptions/Services/SubscriptionDirectory.php',
        // SaaS.7: the operators' cross-tenant invoice and payment lists (headers and tenant names only), and the provider
        // webhook pipeline, which resolves a payment (and so its tenant) from a verified provider reference only.
        'app/Domain/Billing/Services/BillingDirectory.php', 'app/Domain/Payments/Services/PaymentDirectory.php', 'app/Domain/Payments/Services/ProviderEvents.php',
        'app/Domain/Platform/Actions/ProvisionTenantAction.php',
        'app/Http/Controllers/Sso/SsoController.php',
        'app/Support/Tenancy/Jobs/BindTenantContext.php',
        // SaaS.8: a suspended tenant's deferred jobs are stored and released from a worker or the reactivation, before any
        // tenant is bound (rows always filtered by the tenant concerned).
        'app/Support/Tenancy/Jobs/DeferredTenantJobs.php',
        // SaaS.8: the operators' per-tenant operations counts (grouped counts and states only, no tenant records) and the
        // platform audit chain (tenant_id null only).
        'app/Domain/Platform/Services/PlatformOperationsOverview.php',
        'app/Domain/Audit/Services/PlatformAuditTrail.php',
        // SaaS.9: the exit runs from the platform, before or without a bound tenant, always filtered by the tenant concerned:
        // offboarding cases, exports (worker claims, signed downloads), legal holds and their lock, retention evaluation, the
        // deletion plan, its steps and execution, the exit's audit, and the operators' read model.
        'app/Domain/Offboarding/Services/DeletionExecutor.php', 'app/Domain/Offboarding/Services/DeletionPlanner.php',
        'app/Domain/Offboarding/Services/LegalHolds.php', 'app/Domain/Offboarding/Services/OffboardingDirectory.php',
        'app/Domain/Offboarding/Services/TenantDeletions.php', 'app/Domain/Offboarding/Services/TenantExports.php',
        'app/Domain/Offboarding/Services/TenantOffboardings.php', 'app/Domain/Offboarding/Support/OffboardingAudit.php',
        // SaaS.9 closure: a customer's contractual retention terms, read for the tenant concerned by the planner and operators.
        'app/Domain/Offboarding/Services/TenantRetentionTerms.php',
        // SaaS.10: an application's audit trail on the platform chain (tenant_id null, filtered by its reference).
        'app/Domain/Signup/Support/SignupAudit.php',
        // SaaS.12: the deletion register and restore reconciliation run on the platform, with no bound tenant: completed
        // deletions (reference, status, certificate hash, plan) compared with the register, filtered by reference or the
        // tenant concerned. No tenant record beyond the deletion's own is read.
        'app/Domain/Offboarding/Services/DeletionRegister.php', 'app/Domain/Offboarding/Services/DeletionReconciliation.php',
        'app/Support/Tenancy/TenantContext.php',
        // Phase 14: readiness counts platform-wide dead letters (counts only, no tenant data leaves).
        'app/Support/Observability/HealthChecks.php',
        'app/Support/Observability/PlatformReadiness.php',
    ];

    expect(array_values(array_diff(appFilesMatching('/->bypass\(|withoutTenancy\(/'), $allowed)))->toBe([]);
});

it('keeps commercial plans out of HCM code and writes them only through the two commercial services (SaaS.4)', function () {
    // HCM depends on the Entitlements contract only (ADR-0018): no module, policy or job reads a plan or an assignment.
    $readers = [
        'app/Domain/Entitlements/Models/Plan.php', 'app/Domain/Entitlements/Models/PlanVersion.php', 'app/Domain/Entitlements/Models/PlanEntitlement.php',
        'app/Domain/Entitlements/Models/TenantPlanAssignment.php',
        'app/Domain/Entitlements/Services/PlanCatalog.php', 'app/Domain/Entitlements/Services/EntitlementConfiguration.php',
        'app/Domain/Entitlements/Services/EntitlementStateStore.php', 'app/Domain/Entitlements/Services/EntitlementDiagnostics.php',
        'app/Filament/Pages/PlatformPlansPage.php', 'app/Filament/Pages/PlatformEntitlementsPage.php',
        // SaaS.6: subscriptions pin plan versions and show the assignments they project (they write through EntitlementConfiguration).
        'app/Domain/Subscriptions/Models/SubscriptionPeriod.php', 'app/Domain/Subscriptions/Services/CommercialSubscriptions.php',
        'app/Domain/Subscriptions/Services/SubscriptionDirectory.php', 'app/Filament/Pages/PlatformSubscriptionsPage.php',
        // SaaS.7: a price belongs to a published plan version (read only; prices never feed the entitlement engine).
        'app/Domain/Billing/Models/PlanPrice.php', 'app/Domain/Billing/Services/BillingCatalog.php', 'app/Filament/Pages/PlatformBillingCatalogPage.php',
        // SaaS.10: signup reads the plan version on sale and its employee limit, and pins the version accepted (read only);
        // the getting-started page shows a tenant its own plan's employee limit (read only).
        'app/Domain/Signup/Models/SignupApplication.php', 'app/Domain/Signup/Services/SignupCatalogue.php', 'app/Domain/Signup/Services/TenantSetupProgress.php',
    ];
    expect(array_values(array_diff(appFilesMatching('/Entitlements.Models.(Plan|PlanVersion|PlanEntitlement|TenantPlanAssignment)\b/'), $readers)))->toBe([]);

    // Writes: PlanCatalog (the catalogue) and EntitlementConfiguration (assignments) only.
    $writers = ['app/Domain/Entitlements/Services/PlanCatalog.php', 'app/Domain/Entitlements/Services/EntitlementConfiguration.php'];
    expect(array_values(array_diff(appFilesMatching('/\b(Plan|PlanVersion|PlanEntitlement|TenantPlanAssignment)::(query\(\)->)?(create|insert|upsert|update|delete|forceCreate)|new (PlanEntitlement|TenantPlanAssignment)\(/'), $writers)))->toBe([]);
});

it('keeps commercial entitlement out of authorisation: HCM only observes, nothing that authorises reads it (SaaS.5)', function () {
    // The only files outside the entitlement domain that may use it: the shadow call sites, the API middleware, the
    // platform pages and commands, the container bindings and the retention purge.
    $observers = [
        'app/Domain/Ai/Services/AiGateway.php', 'app/Domain/Analytics/Services/ReportRunner.php', 'app/Domain/Analytics/Services/ReportSchedules.php',
        'app/Domain/Attendance/Services/AttendanceProcessor.php', 'app/Domain/Attendance/Services/PunchIngestion.php', 'app/Domain/Enterprise/Services/Webhooks.php',
        'app/Domain/Learning/Services/Learning.php', 'app/Domain/Leave/Services/Leaves.php', 'app/Domain/Lifecycle/Services/LifecycleEngine.php',
        'app/Domain/Payroll/Services/PayrollRuns.php', 'app/Domain/Performance/Services/Appraisals.php', 'app/Domain/Performance/Services/Goals.php',
        'app/Http/Middleware/AuthenticateApiKey.php',
    ];
    $platform = ['app/Console/Commands/EntitlementShadowReport.php', 'app/Console/Commands/ExplainEntitlements.php', 'app/Filament/Pages/PlatformEntitlementsPage.php',
        'app/Filament/Pages/PlatformPlansPage.php', 'app/Providers/AppServiceProvider.php', 'app/Domain/Enterprise/Services/Retention.php',
        // SaaS.6: the commercial subscription lifecycle (platform operations) writes plans in force through the entitlement API.
        'app/Domain/Subscriptions/Models/SubscriptionPeriod.php', 'app/Domain/Subscriptions/Services/CommercialSubscriptions.php',
        'app/Domain/Subscriptions/Services/SubscriptionDirectory.php', 'app/Filament/Pages/PlatformSubscriptionsPage.php',
        // SaaS.7: billing reads published plan versions and pins terms under the tenant's commercial lock; it never evaluates entitlements.
        'app/Domain/Billing/Models/PlanPrice.php', 'app/Domain/Billing/Services/BillingCatalog.php', 'app/Domain/Billing/Services/BillingTerms.php',
        'app/Filament/Pages/PlatformBillingCatalogPage.php',
        // SaaS.9: closing a tenant re-checks its subscriptions under the tenant's commercial lock; it never evaluates entitlements.
        'app/Domain/Offboarding/Services/TenantOffboardings.php',
        // SaaS.10: signup reads the plan version on sale and its employee limit (catalogue data), and the getting-started
        // page shows a tenant its own plan's limit; neither evaluates entitlements.
        'app/Domain/Signup/Models/SignupApplication.php', 'app/Domain/Signup/Services/SignupCatalogue.php', 'app/Domain/Signup/Services/TenantSetupProgress.php'];
    $users = array_values(array_filter(appFilesMatching('/App.Domain.Entitlements/'), fn (string $f) => ! str_starts_with($f, 'app/Domain/Entitlements/')));
    sort($users);
    $allowed = array_merge($observers, $platform);
    sort($allowed);
    expect($users)->toBe($allowed);

    // Nothing that authorises (identity, roles, permissions, scopes, policies, tenancy) references it.
    expect(array_values(array_filter($users, fn (string $f) => preg_match('#^app/(Domain/Identity/|Domain/[^/]+/Policies/|Support/Tenancy/|Policies/)#', $f) === 1)))->toBe([]);

    // Each shadow call site observes and ignores the result: a bare statement, never a value used to decide anything.
    foreach ($observers as $file) {
        $source = file_get_contents(base_path($file));
        preg_match_all('/^.*->observe(Limit)?\(.*$/m', $source, $calls);
        expect($calls[0])->not->toBeEmpty("{$file} should observe");
        foreach ($calls[0] as $line) {
            expect(preg_match('/^\s*(app\(Entitlements::class\)|\$entitlements)->observe(Limit)?\(/', $line))->toBe(1, "{$file}: {$line}");
        }
        expect(preg_match('/(app\(Entitlements::class\)|\$entitlements)->(evaluate|evaluateFor)\(|->(wouldDeny|enforced)\(|DecisionOutcome|Entitlements.Support.Decision|=\s*(app\(Entitlements::class\)|\$entitlements)->observe/', $source))->toBe(0, "{$file} reads an entitlement decision");
    }
});

it('keeps prices out of plans and out of entitlement logic (SaaS.5)', function () {
    // The plan catalogue carries no money: a price will be its own versioned definition (ADR-0037).
    foreach (['plans', 'plan_versions', 'plan_entitlements', 'tenant_plan_assignments'] as $table) {
        $money = array_values(array_filter(Schema::getColumnListing($table),
            fn (string $c) => preg_match('/price|amount|currency|_minor|billing|discount|tax|fee/i', $c) === 1));
        expect($money)->toBe([], "{$table} carries money");
    }
    // No entitlement or subscription code (comments aside) touches pricing, billing, payments or invoices (SaaS.6:
    // subscriptions are commercial lifecycle, not money, so the word itself is no longer banned; the engine's independence
    // from them is the next test).
    foreach (array_merge(glob(app_path('Domain/Entitlements/*/*.php')), glob(app_path('Domain/Subscriptions/*/*.php'))) as $file) {
        $code = collect(token_get_all(file_get_contents($file)))->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($t) => is_array($t) ? $t[1] : $t)->implode('');
        // ("currency" is an HCM core permission prefix, so only the schema check above bans it.)
        expect(preg_match('/price|pricing|billing|invoice|payment/i', $code))->toBe(0, basename($file).' touches pricing or billing');
    }
});

it('keeps the entitlement engine independent of subscriptions: subscriptions feed it, never the reverse (SaaS.6)', function () {
    $engine = glob(app_path('Domain/Entitlements/*/*.php'));
    expect(array_values(array_filter($engine, fn (string $f) => preg_match('/App.Domain.Subscriptions/', file_get_contents($f)) === 1)))->toBe([])
        // Nothing that authorises references the subscription domain either.
        ->and(array_values(array_filter(appFilesMatching('/App.Domain.Subscriptions/'), fn (string $f) => preg_match('#^app/(Domain/Identity/|Domain/[^/]+/Policies/|Support/Tenancy/|Policies/)#', $f) === 1)))->toBe([])
        // Only the subscription domain, its page and its command use it.
        ->and(array_values(array_filter(appFilesMatching('/App.Domain.Subscriptions/'), fn (string $f) => ! str_starts_with($f, 'app/Domain/Subscriptions/'))))
        ->toBe(['app/Console/Commands/SettleSubscriptions.php',
            // SaaS.7: billing reads the subscription timeline (terms pin a price to the plan version in force) and never writes it.
            'app/Domain/Billing/Models/SubscriptionBillingTerm.php', 'app/Domain/Billing/Services/BillingPeriods.php', 'app/Domain/Billing/Services/BillingTerms.php',
            'app/Domain/Billing/Services/Invoices.php',
            // SaaS.7 configuration: a customer's negotiated price belongs to its subscription (read only).
            'app/Domain/Billing/Services/NegotiatedPrices.php', 'app/Domain/Billing/Services/PriceNotices.php',
            // SaaS.9: closing a tenant refuses while a subscription continues (SubscriptionDirectory::continuing, read only).
            'app/Domain/Offboarding/Services/TenantOffboardings.php',
            // SaaS.10: signup provisioning starts the accepted offer's trial (CommercialSubscriptions::startSignupTrial only), and
            // the getting-started page shows a tenant its own period in force (read only).
            'app/Domain/Signup/Services/SignupProvisioning.php', 'app/Domain/Signup/Services/TenantSetupProgress.php',
            'app/Filament/Pages/PlatformBillingAccountsPage.php', 'app/Filament/Pages/PlatformSubscriptionsPage.php'])
        // One writer: only the guarded subscription service projects a subscription onto plan assignments.
        ->and(appFilesMatching('/->projectSubscription\(/'))->toBe(['app/Domain/Subscriptions/Services/CommercialSubscriptions.php']);
});

it('keeps billing, tax and payments out of authorisation, entitlements, HCM, payroll and the subscription lifecycle (SaaS.7)', function () {
    $users = array_values(array_filter(appFilesMatching('/App.Domain.(Billing|Tax|Payments)./'),
        fn (string $f) => preg_match('#^app/Domain/(Billing|Tax|Payments)/#', $f) !== 1));
    expect($users)->toBe(['app/Console/Commands/ProcessBillingProviderEvents.php', 'app/Console/Commands/RunBilling.php',
        // SaaS.9: a deletion waits while financial items are open (Payments\Services\FinancialStanding, read only).
        'app/Domain/Offboarding/Services/DeletionPlanner.php',
        // SaaS.10: signup reads approved policy, markets and prices on sale (read only), and the market's currency.
        'app/Domain/Signup/Services/SignupCatalogue.php', 'app/Domain/Signup/Services/SignupProvisioning.php',
        'app/Filament/Pages/PlatformApprovalsPage.php', 'app/Filament/Pages/PlatformBillingAccountsPage.php',
        'app/Filament/Pages/PlatformBillingCatalogPage.php', 'app/Filament/Pages/PlatformCommercialPoliciesPage.php', 'app/Filament/Pages/PlatformInvoicesPage.php',
        'app/Filament/Pages/PlatformPaymentsPage.php', 'app/Filament/Pages/PlatformTaxSetupPage.php', 'app/Http/Controllers/Billing/ProviderWebhookController.php']);
    // The dependency runs Payments → Billing → Tax: tax is pure, billing never reaches into payments.
    expect(array_values(array_filter(appFilesMatching('/App.Domain.(Billing|Payments|Subscriptions|Entitlements|Payroll|People|Compliance)./'),
        fn (string $f) => str_starts_with($f, 'app/Domain/Tax/'))))->toBe([])
        ->and(array_values(array_filter(appFilesMatching('/App.Domain.Payments./'), fn (string $f) => str_starts_with($f, 'app/Domain/Billing/'))))->toBe([]);
});

it('keeps country-specific tax code inside its jurisdiction module, wired only by the tax registry (SaaS.7)', function () {
    expect(array_values(array_filter(appFilesMatching('/App.Domain.Tax.Jurisdictions./'), fn (string $f) => ! str_starts_with($f, 'app/Domain/Tax/Jurisdictions/'))))
        ->toBe(['app/Domain/Tax/Services/TaxRegistry.php'])
        // No GST-specific code anywhere outside the Tax domain (comments may name examples).
        ->and(array_values(array_filter(appFilesMatching('/\\b(GSTIN|CGST|SGST|IGST|UTGST|IN_GST)\\b/'), fn (string $f) => ! str_starts_with($f, 'app/Domain/Tax/')
            && preg_match('/\\b(GSTIN|CGST|SGST|IGST|UTGST|IN_GST)\\b/', preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(base_path($f)))) === 1)))->toBe([]);
});

it('computes money without floats or FX, and keeps provider code inside its adapters (SaaS.7)', function () {
    $money = collect(appFilesMatching('/./'))->filter(fn (string $f) => preg_match('#^app/(Domain/(Billing|Tax|Payments)|Support/Money)/#', $f) === 1);
    $code = fn (string $f) => preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(base_path($f)));
    expect($money->filter(fn (string $f) => preg_match('/\(float\)|floatval\(|\bround\(|number_format\(|\bfloat \$/', $code($f)) === 1)->values()->all())
        ->toBe(['app/Support/Money/MoneyFormatter.php'])                                    // display only, bounded to exact magnitudes
        ->and($money->filter(fn (string $f) => preg_match('/CurrencyRates|exchange_rates|ExchangeRate/', $code($f)) === 1)->values()->all())->toBe([])
        ->and(appFilesMatching('/Providers.(SandboxProvider|ManualBankTransferProvider|RazorpayProvider)\\b/'))->toBe(['app/Domain/Payments/Services/ProviderRegistry.php'])
        // Only the Razorpay adapter talks HTTP to a provider (SaaS.7 completion: test mode only).
        ->and($money->filter(fn (string $f) => preg_match('/Facades.Http\\b|Http::/', $code($f)) === 1)->values()->all())->toBe(['app/Domain/Payments/Providers/RazorpayProvider.php'])
        // Only payment reconciliation (it records money that already moved) and, SaaS.9, the tenant's own data exit (its export
        // and its approved deletion) run while a tenant is not accessible.
        ->and(appFilesMatching('/implements[^{]*RunsForSuspendedTenants/'))->toBe(['app/Domain/Offboarding/Jobs/ExecuteTenantDeletion.php',
            'app/Domain/Offboarding/Jobs/GenerateTenantExport.php', 'app/Domain/Payments/Jobs/ApplyProviderEvent.php']);
});

it('measures exactly the limits that are observed, and no other (SaaS.5)', function () {
    preg_match_all('/observeLimit\(Capability::(\w+)/', implode("\n", array_map(fn (string $f) => file_get_contents(base_path($f)), appFilesMatching('/observeLimit\(Capability::/'))), $m);
    $observed = collect($m[1])->unique()->sort()->values()->all();
    $measured = collect(Capability::cases())->filter->measured()->map->name->sort()->values()->all();
    expect($observed)->toBe($measured)->and($measured)->toBe(['ActiveEmployeesMax']);
});

it('never builds direct storage urls, reads env() outside config, or leaves debug output in application code', function () {
    expect(appFilesMatching('/Storage::(disk\([^)]*\)->)?url\(/'))->toBe([])
        ->and(appFilesMatching('/[^a-zA-Z_>]env\(/'))->toBe([])
        ->and(appFilesMatching('/^\s*(dd|dump|var_dump|ray)\(/m'))->toBe([]);
});

it('checks form uniqueness within the tenant: every Filament unique() rule is narrowed by TenantUnique (SaaS.8)', function () {
    // Filament's unique() is a raw database rule that ignores the tenant scope. These are the only tables it may check
    // across tenants, because their constraint is global and the value is not another tenant's data to infer.
    $global = [
        'app/Filament/Resources/Tenants/Schemas/TenantForm.php' => 2,          // tenants.slug and the first admin's users.email (platform pages)
        'app/Filament/Resources/Users/Schemas/UserForm.php' => 1,               // users.email is global by design until D-17 is decided
        'app/Filament/Resources/StatutoryProfiles/StatutoryProfileResource.php' => 1, // company_id: a global id offered from the tenant's own companies
    ];
    $found = [];
    foreach (appFilesMatching('/->unique\(/') as $file) {
        if (! str_starts_with($file, 'app/Filament/')) {
            continue;
        }
        $source = file_get_contents(base_path($file));
        // A field rule: unique( with arguments, or a bare unique() on a ::make( chain (collections use the bare form).
        preg_match_all('/->unique\((?!\))(?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)|::make\([^;]*?->unique\(\)/s', $source, $m);
        $blind = array_values(array_filter($m[0], fn (string $call) => ! str_contains($call, 'TenantUnique::rule()')));
        if ($blind !== []) {
            $found[$file] = count($blind);
        }
    }
    ksort($found);
    ksort($global);

    expect($found)->toBe($global);
});

it('classifies every table of the schema in the data inventory, exactly once (SaaS.9)', function () {
    $tenantTables = array_keys(DataInventory::tableCategories());
    $classified = [...$tenantTables, ...array_keys(DataInventory::CHILDREN), ...DataInventory::PLATFORM];
    expect(count($classified))->toBe(count(array_unique($classified)), 'a table is classified twice');
    $schema = collect(Schema::getTables())->filter(fn (array $t) => ($t['schema'] ?? null) === null || $t['schema'] === Schema::getConnection()->getDatabaseName() || $t['schema'] === 'main')
        ->pluck('name')->reject(fn (string $t) => str_starts_with($t, 'sqlite_'))->values()->all();
    sort($schema);
    $missing = array_values(array_diff($schema, $classified));
    expect($missing)->toBe([], 'unclassified tables')
        // Every table with tenant_id is a tenant table of the inventory, and no tenant table lacks it.
        ->and(array_values(array_filter($schema, fn (string $t) => Schema::hasColumn($t, 'tenant_id') && ! in_array($t, $tenantTables, true))))->toBe([])
        ->and(array_values(array_filter($tenantTables, fn (string $t) => ! Schema::hasColumn($t, 'tenant_id'))))->toBe([])
        // Every category is defined, and Markedge's commercial records are never deleted with a tenant.
        ->and(array_values(array_diff(array_keys(DataInventory::TABLES), DataInventory::categories())))->toBe([])
        ->and(collect(DataInventory::CATEGORIES)->where('owner', 'markedge')->every(fn (array $c) => $c['action'] === DataInventory::RETAIN))->toBeTrue();
});

it('deletes audit rows only in the deletion executor, and never reaches platform files with a wildcard (SaaS.9)', function () {
    expect(appFilesMatching("/DB::table\\('audit_events'\\)[^;]*->delete\\(|DB::table\\('audit_event_changes'\\)[^;]*->delete\\(/"))
        ->toBe(['app/Domain/Offboarding/Services/DeletionExecutor.php'])
        ->and(collect(DataInventory::prefixes(new Tenant(['slug' => 'x'])))->flatten(1)->filter(fn (array $p) => str_contains($p['prefix'], '*'))->all())->toBe([]);
});

it('registers a policy for the model of every Filament resource', function () {
    $missing = collect(glob(app_path('Filament/Resources/*/*Resource.php')))
        ->map(fn (string $path) => 'App\\'.str_replace('/', '\\', Str::of($path)->after(app_path().'/')->before('.php')->toString()))
        ->filter(fn (string $class) => class_exists($class) && method_exists($class, 'getModel'))
        ->map(fn (string $class) => $class::getModel())
        ->reject(fn (string $model) => Gate::getPolicyFor($model) !== null)
        ->values()->all();

    expect($missing)->toBe([]);
});

it('makes every queued job tenant-aware (contract §33): TenantAwareJob + BindTenantContext middleware', function () {
    $jobs = collect(appFilesMatching('/implements\s+[^{]*ShouldQueue/'))
        ->map(fn (string $file) => 'App\\'.str_replace('/', '\\', Str::of($file)->after('app/')->before('.php')->toString()))
        ->filter(fn (string $class) => class_exists($class));

    expect($jobs)->not->toBeEmpty();

    foreach ($jobs as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->implementsInterface(TenantAwareJob::class))->toBeTrue("{$class} must implement TenantAwareJob");
        expect($reflection->hasMethod('middleware'))->toBeTrue("{$class} must declare middleware()");

        $source = file_get_contents($reflection->getFileName());
        expect((bool) preg_match('/new\s+BindTenantContext\b/', $source))->toBeTrue("{$class} must return BindTenantContext from middleware()");
    }
});

it('never acquires a hard RecruitmentEdge / RMS dependency (contract §3)', function () {
    $prohibited = '~namespace\s+[^;]*\\\\Rms\b|namespace\s+[^;]*RecruitmentEdge|use\s+[^;]*(RecruitmentEdge|\\\\Rms\\\\)|Rms(Model|Service|Repository|Connection)\b|connection\(["\']rms["\']\)~i';

    expect(appFilesMatching($prohibited))->toBe([]);

    $migrations = collect(glob(database_path('migrations/*.php')))
        ->filter(fn (string $path) => preg_match('/Schema::(create|table)\([\'"]rms_|foreign\([\'"]rms_|references\([\'"][a-z_]*[\'"]\)->on\([\'"]rms_/i', file_get_contents($path)))
        ->values()->all();
    expect($migrations)->toBe([]);

    $connections = array_keys(config('database.connections'));
    expect(array_filter($connections, fn (string $name) => str_contains(strtolower($name), 'rms')))->toBe([]);

    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    $packages = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));
    expect(array_filter($packages, fn (string $name) => preg_match('/rms|recruitmentedge/i', $name)))->toBe([]);
});

it('masks every highly sensitive attribute in the data classification map (contract §17)', function () {
    $globallyMasked = config('peopleos.audit.sensitive_attributes', []);

    foreach (config('peopleos.data_classification.highly_sensitive') as $class => $attributes) {
        expect(class_exists($class))->toBeTrue("{$class} in data classification must exist");
        $model = new $class;
        $modelMasked = method_exists($model, 'auditSensitiveAttributes') ? $model->auditSensitiveAttributes() : [];
        $excluded = method_exists($model, 'auditExcludedAttributes') ? $model->auditExcludedAttributes() : [];
        $encrypted = array_keys(array_filter($model->getCasts(), fn ($cast) => str_starts_with((string) $cast, 'encrypted') || $cast === 'hashed'));

        foreach ($attributes as $attribute) {
            $protected = in_array($attribute, $modelMasked, true) || in_array($attribute, $globallyMasked, true)
                || in_array($attribute, $excluded, true) || in_array($attribute, $encrypted, true);
            expect($protected)->toBeTrue("{$class}::{$attribute} must be masked, excluded or encrypted");
        }
    }

    foreach (['financial', 'statutory', 'confidential'] as $level) {
        foreach (config("peopleos.data_classification.{$level}") as $class) {
            expect(class_exists($class))->toBeTrue("{$class} in data classification must exist");
        }
    }
});

it('keeps the attendance domain free of payroll money, duplicate identity, approval and audit frameworks', function () {
    $attendance = appFilesMatching('/./'); // all app files, filtered below
    $attendanceFiles = array_values(array_filter($attendance, fn (string $f) => str_starts_with($f, 'app/Domain/Attendance/')));
    expect($attendanceFiles)->not->toBeEmpty();

    foreach ($attendanceFiles as $file) {
        $source = file_get_contents(base_path($file));
        // No money: attendance produces quantities only (contract §41).
        expect((bool) preg_match('/\\b(ctc|salary|payslip|earning|deduction|tds|esi_rate|pf_rate|wage)\\b/i', $source))->toBeFalse("{$file} must not compute payroll amounts");
        // No second identity, approval or audit framework.
        expect((bool) preg_match('/class\\s+(AttendanceEmployee|Worker|Staff)\\b|Schema::create\\(.*(worker|staff)/i', $source))->toBeFalse("{$file} must not define a worker identity");
        expect((bool) preg_match('/AttendanceAudit|class\\s+\\w*ApprovalEngine/', $source))->toBeFalse("{$file} must reuse the platform audit and approval engines");
        expect((bool) preg_match('/RecruitmentEdge|\\bRms\\b/', $source))->toBeFalse("{$file} must not reference RMS");
    }

    // Attendance models are tenant-scoped and audited or documented as evidence/derived tables. The holiday library is
    // platform configuration that tenants copy from (see the platform-level models above).
    foreach (glob(app_path('Domain/Attendance/Models/*.php')) as $path) {
        $class = 'App\\Domain\\Attendance\\Models\\'.basename($path, '.php');
        if (in_array($class, [HolidayLibraryList::class, HolidayLibraryEntry::class, CountryRegion::class], true)) {
            continue;
        }
        expect(in_array(BelongsToTenant::class, class_uses_recursive($class), true))->toBeTrue("{$class} must be tenant-scoped");
    }
});

it('keeps the leave domain on its ledger, off attendance punches and payroll money, and behind LeaveDayResolver', function () {
    $leaveFiles = array_values(array_filter(appFilesMatching('/./'), fn (string $f) => str_starts_with($f, 'app/Domain/Leave/')));
    expect($leaveFiles)->not->toBeEmpty();

    foreach ($leaveFiles as $file) {
        $source = file_get_contents(base_path($file));
        expect((bool) preg_match('/AttendancePunch/', $source))->toBeFalse("{$file} must not touch raw punches");
        expect((bool) preg_match('/\\b(ctc|salary|payslip|earning|deduction|wage)\\b/i', $source))->toBeFalse("{$file} must not compute payroll amounts");
        expect((bool) preg_match('/RecruitmentEdge|\\bRms\\b/', $source))->toBeFalse("{$file} must not reference RMS");
        // Balances change only through the ledger service: no direct writes to leave_balances outside LeaveBalances.
        if (! str_ends_with($file, 'Services/LeaveBalances.php')) {
            expect((bool) preg_match('/LeaveBalance::(create|query\\(\\)->(update|insert))|->(increment|decrement)\\([\'"](closing|used|accrued)/', $source))->toBeFalse("{$file} must post ledger entries instead of editing balances");
        }
    }

    // Attendance calculation consumes leave only through the contract (the record model keeps a plain
    // leave_request_id relation for display, which is allowed).
    foreach (array_filter(appFilesMatching('/./'), fn (string $f) => preg_match('#^app/Domain/Attendance/(Services|Jobs|Imports|Contracts)/#', $f)) as $file) {
        expect((bool) preg_match('/use App\\\\Domain\\\\Leave\\\\(Models|Services)/', file_get_contents(base_path($file))))->toBeFalse("{$file} must use LeaveDayResolver, not Leave internals");
    }

    expect(in_array(AttendanceLeaveDayResolver::class, array_map(fn ($c) => $c, [get_class(app(LeaveDayResolver::class))]), true))->toBeTrue();
});

it('makes payroll consume attendance and leave outputs, never their internals, and keep statutory rates out of PHP', function () {
    $payroll = array_values(array_filter(appFilesMatching('/./'), fn (string $f) => preg_match('#^app/Domain/Payroll/(Services|Jobs)/#', $f)));
    expect($payroll)->not->toBeEmpty();

    foreach ($payroll as $file) {
        $source = file_get_contents(base_path($file));
        // payroll consumes AttendanceOutput / LeaveOutput; it may lock days (PayrollRuns) but never read punches or recalculate.
        expect((bool) preg_match('/AttendancePunch|AttendanceProcessor|LeaveBalances|LeaveAccrual|LeaveLedgerEntry/', $source))->toBeFalse("{$file} must use AttendanceOutput / LeaveOutput");
        if (! str_ends_with($file, 'Services/PayrollRuns.php')) {
            expect((bool) preg_match('/AttendanceRecord::/', $source))->toBeFalse("{$file} must read attendance through AttendanceOutput");
        }
        expect((bool) preg_match('/RecruitmentEdge|\\bRms\\b/', $source))->toBeFalse("{$file} must not reference RMS");
        // No statutory rate literals in the engine: they live in versioned compliance rules.
        expect((bool) preg_match('/\\b0\\.(12|0075|0325|0833)\\b|\\b(15000|21000)\\b/', $source))->toBeFalse("{$file} must not embed statutory rates");
    }
});

/*
 | UX.15 closure: every resource page is composed from the PeopleOS experience layer (context, lens, review,
 | record context), never a bare Filament page.
 */
it('builds every resource page on a PeopleOS base page', function () {
    $bases = [
        PeopleListRecords::class, PeopleManageRecords::class,
        PeopleCreateRecord::class, PeopleEditRecord::class,
        PeopleViewRecord::class,
    ];
    $offenders = collect(glob(app_path('Filament/Resources/*/Pages/*.php')))
        ->map(fn (string $path) => 'App\\'.str_replace('/', '\\', Str::of($path)->after(app_path().'/')->before('.php')->toString()))
        ->filter(fn (string $class) => class_exists($class) && ! (new ReflectionClass($class))->isAbstract())
        ->filter(fn (string $class) => is_subclass_of($class, ListRecords::class) || is_subclass_of($class, CreateRecord::class)
            || is_subclass_of($class, EditRecord::class) || is_subclass_of($class, ViewRecord::class))
        ->reject(fn (string $class) => collect($bases)->contains(fn (string $base) => is_subclass_of($class, $base)))
        ->values()->all();

    expect($offenders)->toBe([]);
});

it('keeps prices, tax rates, tax identifiers and Markedge policy out of commercial code: they are data (SaaS.7 configuration gate)', function () {
    $commercial = array_values(array_filter(appFilesMatching('/./'), fn (string $f) => preg_match('#^app/(Domain/(Billing|Tax|Payments)|Support/(Money|Commercial)|Filament/Pages/Platform)#', $f) === 1));
    $matching = fn (string $pattern) => array_values(array_filter($commercial, fn (string $f) => preg_match($pattern, file_get_contents(base_path($f))) === 1));
    expect($matching("/['\"]rate['\"]\\s*=>\\s*['\"]?[0-9]/"))->toBe([])                          // no tax rate in code (rules are versioned data)
        ->and($matching('/Money::(parse|ofMinor)\(\s*[\'"]?[0-9]/'))->toBe([])                   // no literal amount (prices are versions)
        ->and($matching('/\b[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]\b/'))->toBe([])      // no GSTIN
        ->and($matching('/\b[A-Z]{5}[0-9]{4}[A-Z]\b/'))->toBe([])                               // no PAN
        ->and($matching('/\b99[0-9]{4}\b/'))->toBe([])                                          // no SAC (a rule's classification is data)
        // One source for Markedge policy: only CommercialConfiguration reads the shipped defaults; no setting duplicates them.
        ->and(appFilesMatching("/config\\(\\s*'peopleos\\.commercial\\.policy_defaults/"))->toBe(['app/Domain/Billing/Services/CommercialConfiguration.php'])
        ->and(appFilesMatching('/peopleos\.billing\.(b2b_only|payment_terms_days|price_increase_notice_days|proration_rounding)/'))->toBe([]);
});

it('keeps self-service signup inside its boundary: one trial entry point, no operator accounts, one status writer (SaaS.10)', function () {
    // Only signup provisioning starts a signup trial (a trial of the accepted offer, never a paid subscription).
    expect(appFilesMatching('/->startSignupTrial\(/'))->toBe(['app/Domain/Signup/Services/SignupProvisioning.php']);

    // Signup never creates or names a platform operator: the operator flag appears nowhere in its code or pages.
    $signup = array_merge(glob(app_path('Domain/Signup/*/*.php')), glob(app_path('Filament/Signup/*.php')), [app_path('Filament/Pages/PlatformSignupsPage.php')]);
    expect($signup)->not->toBeEmpty();
    foreach ($signup as $file) {
        expect(file_get_contents($file))->not->toContain('is_platform_admin', basename($file));
    }

    // An application's state changes only through SignupTransitions (conditional, along SignupStatus::TRANSITIONS); the
    // first insert (SignupApplications::start) is the one other place a status is written.
    $writers = array_values(array_filter(appFilesMatching('/SignupApplication\b/'), fn (string $f) => preg_match("/'status'\\s*=>\\s*(SignupStatus::[A-Z]|\\\$to->value)/", file_get_contents(base_path($f))) === 1));
    expect($writers)->toBe(['app/Domain/Signup/Services/SignupApplications.php', 'app/Domain/Signup/Support/SignupTransitions.php']);

    // The first administrator is the Account Owner, an explicit role: never the `*` template.
    expect(config('peopleos.roles.account-owner.permissions'))->not->toContain('*')
        ->and(file_get_contents(app_path('Domain/Signup/Services/SignupProvisioning.php')))->toContain("'account-owner'")->not->toContain('tenant-super-admin');
});
