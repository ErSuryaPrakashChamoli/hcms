<?php

use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
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
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\People\Models\Person;
use App\Domain\Platform\Models\Tenant;
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
        'app/Filament/Pages/PlatformBillingCatalogPage.php'];
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
            'app/Filament/Pages/PlatformBillingAccountsPage.php', 'app/Filament/Pages/PlatformSubscriptionsPage.php'])
        // One writer: only the guarded subscription service projects a subscription onto plan assignments.
        ->and(appFilesMatching('/->projectSubscription\(/'))->toBe(['app/Domain/Subscriptions/Services/CommercialSubscriptions.php']);
});

it('keeps billing, tax and payments out of authorisation, entitlements, HCM, payroll and the subscription lifecycle (SaaS.7)', function () {
    $users = array_values(array_filter(appFilesMatching('/App.Domain.(Billing|Tax|Payments)./'),
        fn (string $f) => preg_match('#^app/Domain/(Billing|Tax|Payments)/#', $f) !== 1));
    expect($users)->toBe(['app/Console/Commands/ProcessBillingProviderEvents.php', 'app/Console/Commands/RunBilling.php',
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
        // Only payment reconciliation runs while a tenant is suspended (it records money that already moved).
        ->and(appFilesMatching('/implements[^{]*RunsForSuspendedTenants/'))->toBe(['app/Domain/Payments/Jobs/ApplyProviderEvent.php']);
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

    // Attendance models are tenant-scoped and audited or documented as evidence/derived tables.
    foreach (glob(app_path('Domain/Attendance/Models/*.php')) as $path) {
        $class = 'App\\Domain\\Attendance\\Models\\'.basename($path, '.php');
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
