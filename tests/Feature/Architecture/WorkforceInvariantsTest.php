<?php

use App\Domain\Workforce\Events\WorkforceEvent;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Phase 10 §57: workforce planning architecture invariants. Static checks of the code base (comments
 | stripped, so a docblock stating a boundary is not a hit); the behaviour behind each is covered by
 | tests/Feature/Workforce.
 */

function workforceSource(string $path): string
{
    return collect(token_get_all(file_get_contents($path)))->map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t)->implode('');
}

/** @return array<string, string> path => code without comments */
function workforceFiles(bool $withUi = true): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Workforce'))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[Str::after($file->getPathname(), base_path().'/')] = workforceSource($file->getPathname());
        }
    }
    if ($withUi) {
        $ui = [
            ...glob(app_path('Filament/Resources/{Positions,WorkforcePlans,WorkforceScenarios,WorkforceBudgets}/{*,*/*}.php'), GLOB_BRACE),
            ...array_map(fn ($page) => app_path("Filament/Pages/{$page}.php"), ['WorkforceDashboard', 'PositionHierarchyPage', 'VacanciesPage', 'WorkforceSnapshotsPage', 'WorkforceAnalyticsPage', 'TeamWorkforce']),
            app_path('Http/Controllers/Api/V1/PositionController.php'), app_path('Http/Controllers/Api/V1/WorkforceController.php'), app_path('Filament/Support/WorkforceActions.php'),
        ];
        foreach ($ui as $path) {
            $files[Str::after($path, base_path().'/')] = workforceSource($path);
        }
    }
    ksort($files);

    return $files;
}

function workforceFilesMatching(string $pattern, bool $withUi = true): array
{
    return array_keys(array_filter(workforceFiles($withUi), fn ($code) => preg_match($pattern, $code)));
}

it('does not depend on RMS and contains no recruitment concepts', function () {
    expect(workforceFilesMatching('~\b(RecruitmentEdge|Rms[A-Z]\w*|rms_\w+|candidate\w*|requisition\w*|job_application|applicant\w*|interview\w*|offer_letter|offer_id|resume|recruit\w*|sourcing|ats_\w+)\b~i'))->toBe([]);
    foreach (glob(database_path('migrations/2026_10_10_*.php')) as $migration) {
        expect(workforceSource($migration))->not->toMatch('~rms_|candidate|requisition|recruit|applicant|interview|offer_~i');
    }
});

it('never writes payroll, statutory or compensation data and reads payroll only through its read contract', function () {
    expect(workforceFilesMatching('~use\s+App\\\\Domain\\\\Payroll\\\\(?!Contracts\\\\WorkforceCostReader;)~'))->toBe([])
        ->and(workforceFilesMatching('~use\s+App\\\\Domain\\\\Compliance\\\\~'))->toBe([])
        ->and(workforceFilesMatching('~\b(PayrollRun|PayrollEntry|Payslip|EmployeeSalaryAssignment|SalaryStructure|SalaryComponent|ComplianceRule|StatutoryReturn|EpfReturn|TdsAnnualLedger|Salaries)\b~'))->toBe([])
        ->and(workforceFilesMatching('~\b(increment|bonus|salary_revision|pay_recommendation|compensation_cycle)\b~i'))->toBe([]);

    // The payroll side of the contract only reads.
    expect(workforceSource(app_path('Domain/Payroll/Services/WorkforceCost.php')))->not->toMatch('~->(create|update|delete|save|insert|forceFill)\(~');

    $offenders = [];
    foreach (['Domain/Payroll', 'Domain/Compliance'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match('~App\\\\Domain\\\\Workforce\\\\~', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('never promotes, transfers, terminates or moves employees itself', function () {
    expect(workforceFilesMatching('~\b(AssignPositionAction|PromoteEmployeeAction|TransferEmployeeAction|RehireEmployeeAction|LifecycleEngine|ChangeManagerAction)\b|->transitionTo\(~', false))->toBe([])
        ->and(workforceFilesMatching('~EmployeePosition::(query\(\)->)?(create|insert|update)|new\s+EmployeePosition\b|->assignments\(\)->(create|update)~'))->toBe([])
        ->and(workforceFilesMatching('~\b(AttritionRisk|AiGateway|AiProvider|flight_?risk|autoPromote|stackRank|should_be_promoted)\b~i'))->toBe([]);
});

it('keeps a position distinct from an employee', function () {
    foreach (['positions', 'position_versions'] as $table) {
        expect(Schema::hasColumn($table, 'employee_id'))->toBeFalse("{$table} must not hold an employee");
    }
    expect((new Position)->getFillable())->not->toContain('employee_id')
        ->and((new PositionVersion)->getFillable())->not->toContain('employee_id')
        // The one link is the employee's own assignment row.
        ->and(Schema::hasColumn('employee_positions', 'position_id'))->toBeTrue()
        // Occupancy is never a typed lifecycle status.
        ->and(array_keys(config('peopleos.workforce.position_statuses')))->not->toContain('occupied')
        ->and(collect(config('peopleos.workforce.position_transitions'))->flatten()->all())->not->toContain('occupied');
});

it('keeps scenarios and plans away from live workforce data except the explicit propose-position action', function () {
    $planning = ['WorkforcePlans.php', 'WorkforceScenarios.php', 'WorkforceBudgets.php', 'WorkforceForecast.php', 'WorkforceAnalytics.php', 'WorkforceSnapshot.php', 'WorkforceReminders.php'];
    foreach ($planning as $file) {
        $code = workforceSource(app_path("Domain/Workforce/Services/{$file}"));
        expect($code)->not->toMatch('~(Position|PositionVersion|EmployeePosition)::query\(\)->(create|update|insert)|->update\(\[[^\]]*\'status\'\s*=>\s*\'(open|abolished|closed|frozen)\'~');
    }
    $plans = workforceSource(app_path('Domain/Workforce/Services/WorkforcePlans.php'));
    expect(preg_match_all('~\$this->positions->(create|transition)\(~', $plans))->toBe(2)   // both inside proposePositionFromLine
        ->and($plans)->toMatch("~->transition\(\\\$position, 'proposed'~");
});

it('preserves tenant context in models, tables, jobs and events', function () {
    foreach (workforceFiles(false) as $path => $code) {
        if (str_contains($path, '/Models/')) {
            expect($code)->toMatch('~use\s+[^;]*\bBelongsToTenant\b~');
        }
        if (str_contains($path, '/Jobs/')) {
            expect($code)->toContain('TenantAwareJob')->toContain('new BindTenantContext')->toContain('ShouldBeUnique');
        }
    }
    foreach (['positions', 'position_versions', 'position_change_requests', 'workforce_scenarios', 'workforce_plans', 'workforce_plan_versions', 'workforce_plan_lines', 'workforce_budgets', 'workforce_reminder_logs'] as $table) {
        expect(Schema::hasColumn($table, 'tenant_id'))->toBeTrue("{$table}.tenant_id");
    }
    $parameter = (new ReflectionClass(WorkforceEvent::class))->getConstructor()->getParameters()[2];
    expect($parameter->getName())->toBe('subject')->and($parameter->getType()->getName())->toBe(Model::class);
});

it('derives manager scope from the relationship resolver and position hierarchy, never from manager_id shortcuts or reporting lines', function () {
    expect(workforceFilesMatching('~->directReports\(\)|where\(\s*[\'"]manager_id[\'"]\s*,~'))->toBe([])
        ->and(workforceFilesMatching('~ReportingRelationship::(query\(\)->)?(create|update|insert)~'))->toBe([]);
});

it('keeps costs out of the API without the costs scope and out of webhooks', function () {
    expect(workforceSource(app_path('Http/Controllers/Api/V1/PositionController.php')))->not->toMatch('~cost|salary|amount~i');
    $workforce = workforceSource(app_path('Http/Controllers/Api/V1/WorkforceController.php'));
    $gated = collect(explode("\n", $workforce))->filter(fn ($line) => str_contains($line, '$costs ?'))->implode("\n");
    expect(preg_match_all('~planned_cost~', $workforce))->toBe(preg_match_all('~planned_cost~', $gated))->and($gated)->not->toBe('')
        ->and(collect(config('peopleos.enterprise.webhook_events'))->filter(fn ($e) => str_starts_with($e, 'workforce.'))->every(fn ($e) => str_starts_with($e, 'workforce.position.')))->toBeTrue()
        ->and(config('peopleos.enterprise.webhook_events'))->not->toContain('workforce.position.occupied', 'workforce.budget.approved', 'workforce.plan.published');
});
