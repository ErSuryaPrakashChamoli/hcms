<?php

use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Compliance\Models\ComplianceEvidenceDocument;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Compliance\Models\ComplianceRuleParameter;
use App\Domain\Compliance\Models\ComplianceRuleVerification;
use App\Domain\Compliance\Models\ProfessionalTaxRuleVersion;
use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\ArticleRead;
use App\Domain\Learning\Models\LearningInstructor;
use App\Domain\Leave\Services\AttendanceLeaveDayResolver;
use App\Domain\People\Models\Person;
use App\Domain\Platform\Models\Tenant;
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
