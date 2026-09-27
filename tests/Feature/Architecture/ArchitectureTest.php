<?php

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\ArticleRead;
use App\Domain\People\Models\Person;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\Jobs\TenantAwareJob;
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
        'ServiceDesk\Models\TicketComment',
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
        'app/Domain/Platform/Actions/ProvisionTenantAction.php',
        'app/Http/Controllers/Sso/SsoController.php',
        'app/Support/Tenancy/Jobs/BindTenantContext.php',
        'app/Support/Tenancy/TenantContext.php',
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
