<?php

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\CompensationBudget;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationRange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\CompensationRelationManager;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
 | Phase 11 §36: compensation architecture invariants. Static checks of the code base (comments
 | stripped, so a docblock stating a boundary is not a hit) plus the behaviour behind the ones a scan
 | cannot prove. Behaviour in depth: tests/Feature/Compensation.
 */

function compensationInvariantSource(string $path): string
{
    return collect(token_get_all(file_get_contents($path)))->map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t)->implode('');
}

/** @return array<string, string> path => code without comments, for the Compensation domain and its screens / API */
function compensationFiles(bool $withUi = true): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Compensation'))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[Str::after($file->getPathname(), base_path().'/')] = compensationInvariantSource($file->getPathname());
        }
    }
    if ($withUi) {
        $ui = [
            ...glob(app_path('Filament/Resources/{CompensationChanges,CompensationRanges,CompensationBudgets,CompensationCycles,SalaryStructures}/{*,*/*}.php'), GLOB_BRACE),
            app_path('Filament/Resources/Employees/RelationManagers/CompensationRelationManager.php'), app_path('Filament/Resources/Employees/RelationManagers/CompensationChangesRelationManager.php'),
            app_path('Filament/Pages/MyCompensation.php'), app_path('Filament/Pages/CompensationAnalyticsPage.php'), app_path('Filament/Support/CompensationActions.php'),
            app_path('Http/Controllers/Api/V1/CompensationController.php'),
        ];
        foreach ($ui as $path) {
            $files[Str::after($path, base_path().'/')] = compensationInvariantSource($path);
        }
    }
    ksort($files);

    return $files;
}

/** @return list<string> */
function compensationFilesMatching(string $pattern, bool $withUi = true): array
{
    return array_keys(array_filter(compensationFiles($withUi), fn (string $code) => preg_match($pattern, $code) === 1));
}

it('1. keeps every compensation record tenant scoped', function () {
    foreach (glob(app_path('Domain/Compensation/Models/*.php')) as $path) {
        $class = 'App\\Domain\\Compensation\\Models\\'.basename($path, '.php');
        expect(in_array(BelongsToTenant::class, class_uses_recursive($class), true))->toBeTrue("{$class} is not tenant scoped");
    }
});

it('2–3. never touches payroll statutory configuration, payroll runs or finalisation', function () {
    expect(compensationFilesMatching('~\b(ComplianceRule|CompanyStatutoryProfile|EstablishmentStatutoryProfile|StatutoryRegistration|StatutoryReturn|StatutorySnapshot|EpfReturn\w*|EsiReturn\w*|TdsAnnualLedger|TdsCertificate|EmployeeTaxDeclaration|StatutoryEngine|ComplianceRules)\b~'))->toBe([])
        ->and(compensationFilesMatching('~\b(PayrollRun|PayrollEntry|PayrollEntryLine|Payslip|PayrollAdjustment|PayrollRuns|PayrollCalculator|Payslips)\b~'))->toBe([])
        // Payroll's component catalogue is read, never written, from Compensation.
        ->and(compensationFilesMatching('~SalaryComponent::(create|insert|upsert|updateOrCreate|firstOrCreate|forceCreate)|SalaryComponent::query\(\)->[^;]*->(update|delete|insert)\(~'))->toBe([]);
});

it('4–5. never writes Performance, Career, Talent or Succession records', function () {
    expect(compensationFilesMatching('~App\\\\Domain\\\\(Career|Talent|Succession|Skills|Learning|Development)\\\\~'))->toBe([])
        ->and(compensationFilesMatching('~\b(Appraisal|Goal|ImprovementPlan|Calibration\w*|FeedbackEntry|OneOnOne|PerformanceReview|Appraisals|Goals|Calibrations)\b~'))->toBe([])
        // The only Performance dependencies: the finalized-outcome read contract, the manager-relationship resolver and the cycle reference.
        ->and(collect(compensationFiles())->flatMap(fn ($code) => preg_match_all('~App\\\\Domain\\\\Performance\\\\[\\\\\w]+~', $code, $m) ? $m[0] : [])->unique()->sort()->values()->all())
        ->toBe(['App\\Domain\\Performance\\Contracts\\PerformanceOutcomesReader', 'App\\Domain\\Performance\\Models\\PerformanceCycle', 'App\\Domain\\Performance\\Services\\PerformanceRelationships']);
});

it('6. never changes workforce capacity, positions or plans implicitly', function () {
    expect(compensationFilesMatching('~\b(Positions|PositionSeats|PositionHierarchy|AssignPositionAction|WorkforcePlans|WorkforceScenarios|WorkforceBudgets)\b~'))->toBe([])
        ->and(compensationFilesMatching('~(Position|PositionVersion|WorkforcePlan|WorkforcePlanVersion|WorkforcePlanLine|WorkforceBudget)::(create|insert|upsert|updateOrCreate|firstOrCreate)|->(position|plan)\(\)->(update|delete|create)~'))->toBe([]);
});

it('7. has no RMS / RecruitmentEdge dependency', function () {
    expect(compensationFilesMatching('~recruitment|RecruitmentEdge|\brms\b|Requisition|Candidate~i'))->toBe([]);
});

it('8–9. keeps the compensation assignment distinct from Employee and from Position', function () {
    $pay = '~(ctc|salary|compensation|pay_|wage|midpoint)~i';
    foreach (['employees', 'people', 'employee_positions', 'positions', 'position_versions'] as $table) {
        expect(collect(Schema::getColumnListing($table))->filter(fn ($c) => preg_match($pay, $c))->values()->all())->toBe([], "{$table} carries pay");
    }
    expect((new EmployeeSalaryAssignment)->getTable())->toBe('employee_salary_assignments');
});

it('10–11. keeps approved compensation immutable and history reconstructable (nothing deletes it)', function () {
    expect(appFilesMatchingInvariant('~(EmployeeSalaryAssignment|employee_salary_assignments)[^;]*->(delete|forceDelete|truncate)\(~'))->toBe([])
        ->and(appFilesMatchingInvariant('~EmployeeSalaryAssignment::(query\(\)->)?(create|insert|upsert|forceCreate|updateOrCreate|firstOrCreate)\b|DB::table\([\'"]employee_salary_assignments~'))->toBe(['app/Domain/Compensation/Services/AssignmentWriter.php'])
        ->and(EmployeeSalaryAssignment::MUTABLE)->toBe(['effective_to', 'status', 'active_key', 'superseded_by_id', 'updated_at'])
        ->and(CompensationChange::CONTENT)->toContain('ctc_annual', 'effective_from', 'salary_structure_id', 'component_values');

    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    actAsTenant(provisionTenant());
    payrollCompany();
    $employee = salariedEmployee(600000, ['task.view'], '2026-04-01');
    $row = EmployeeSalaryAssignment::query()->where('employee_id', $employee->id)->sole();
    expect(fn () => $row->update(['ctc_annual' => 1]))->toThrow(RuntimeException::class)->and(fn () => $row->delete())->toThrow(RuntimeException::class, 'never deleted');
});

it('12. never lets Payroll consume draft, submitted, rejected or cancelled compensation', function () {
    // The contract reads active canonical rows only (each query is ->active() or goes through overlapping(),
    // which filters status = active), and only an executed approved change writes a row.
    $ledger = compensationInvariantSource(app_path('Domain/Compensation/Services/CompensationLedger.php'));
    expect(substr_count($ledger, 'EmployeeSalaryAssignment::query()'))->toBe(substr_count($ledger, 'EmployeeSalaryAssignment::query()->active()') + substr_count($ledger, 'overlapping(EmployeeSalaryAssignment::query()'))
        ->and(substr_count($ledger, "where('status', 'active')"))->toBe(1);

    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    actAsTenant(provisionTenant());
    payrollCompany();
    $employee = salariedEmployee(600000, ['task.view'], '2026-04-01');
    $actors = compensationActors();
    $changes = app(CompensationChanges::class);
    $structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    foreach (['draft' => 0, 'submitted' => 1, 'rejected' => 2, 'approved' => 3] as $stop => $steps) {
        $c = $changes->propose($employee, ['change_type' => 'market_adjustment', 'effective_from' => '2026-09-'.(10 + $steps), 'salary_structure_id' => $structure->id, 'ctc_annual' => 900000 + $steps, 'reason' => $stop], $actors['proposer']);
        $steps >= 1 && $changes->submit($c, $actors['proposer']);
        $stop === 'rejected' && $changes->reject($c, $actors['reviewer'], 'no');
        if ($stop === 'approved') {
            $changes->review($c, $actors['reviewer']);
            $changes->approve($c, $actors['approver']);
        }
    }
    expect(app(CompensationOutput::class)->history($employee)->pluck('ctcAnnual')->all())->toBe([600000.0]);
});

it('13. keeps sensitive compensation fields permission controlled and classified', function () {
    $config = collect(config('peopleos'))->first(fn ($v) => is_array($v) && isset($v['financial'], $v['highly_sensitive']));
    expect($config['financial'])->toContain(EmployeeSalaryAssignment::class, CompensationChange::class, CompensationRange::class, CompensationBudget::class)
        ->and($config['highly_sensitive'][CompensationChange::class])->toBe(['internal_notes'])
        ->and((new EmployeeSalaryAssignment)->auditSensitiveAttributes())->toContain('ctc_annual', 'component_values')
        ->and((new CompensationChange)->auditSensitiveAttributes())->toContain('ctc_annual', 'reason', 'internal_notes');

    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $tenant = provisionTenant();
    actAsTenant($tenant);
    payrollCompany();
    $employee = salariedEmployee(600000, ['task.view'], '2026-04-01');
    $this->actingAs(tenantUser($tenant, ['employee.view', 'employee.sensitive.view', 'payroll.view', 'payroll.manage']));
    expect(CompensationRelationManager::canViewForRecord($employee, ViewEmployee::class))->toBeFalse();   // payroll permissions grant no salary access
});

it('14. makes every queued compensation job carry its tenant', function () {
    $jobs = glob(app_path('Domain/Compensation/Jobs/*.php'));
    expect($jobs)->not->toBeEmpty();
    foreach ($jobs as $path) {
        $class = 'App\\Domain\\Compensation\\Jobs\\'.basename($path, '.php');
        $job = app($class);
        expect($job)->toBeInstanceOf(TenantAwareJob::class)
            ->and(collect($job->middleware())->contains(fn ($m) => $m instanceof BindTenantContext))->toBeTrue();
    }
});

it('15. never turns a compensation decision into an automatic employment decision', function () {
    expect(compensationFilesMatching('~\b(LifecycleEngine|HireEmployeeAction|TerminateEmployee\w*|PromoteEmployeeAction|TransferEmployeeAction|ChangeManagerAction|AssignPositionAction)\b|->transitionTo\(|EmployeePosition::(query\(\)->)?(create|insert|update)|ReportingRelationship::(query\(\)->)?(create|insert|update)~'))->toBe([])
        ->and(compensationFilesMatching('~Employee::query\(\)[^;]*->update\(|\$employee->(update|save)\(~'))->toBe([])
        // Nothing listens to other modules' events to change pay: Compensation has no listeners.
        ->and(is_dir(app_path('Domain/Compensation/Listeners')))->toBeFalse();
});

it('keeps Payroll, Letters, Exit and AI on the read contract only', function () {
    $outside = fn (string $dir) => collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))))
        ->filter(fn ($f) => $f->isFile() && $f->getExtension() === 'php' && preg_match('~\b(EmployeeSalaryAssignment|AssignmentWriter|CompensationChanges|CompensationCycles|SalaryStructureVersion)\b~', compensationInvariantSource($f->getPathname())))
        ->map(fn ($f) => Str::after($f->getPathname(), base_path().'/'))->values()->all();

    foreach (['Domain/Payroll', 'Domain/Letters', 'Domain/Exit', 'Domain/Ai', 'Domain/Analytics', 'Domain/Compliance'] as $dir) {
        expect($outside($dir))->toBe([], "{$dir} reaches past the CompensationOutput contract");
    }
});

/** @return list<string> files under app/ whose code (comments stripped) matches */
function appFilesMatchingInvariant(string $pattern): array
{
    return collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())))
        ->filter(fn ($f) => $f->isFile() && $f->getExtension() === 'php' && preg_match($pattern, compensationInvariantSource($f->getPathname())))
        ->map(fn ($f) => Str::after($f->getPathname(), base_path().'/'))->sort()->values()->all();
}
