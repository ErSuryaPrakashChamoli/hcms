<?php

use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../../Payroll/PayrollTestHelpers.php';

/* Data import DI-4 (Payroll) test helpers: every helper is prefixed di4 (top-level functions are global across the suite). */

/** Stage a CSV the way the Data import page does: register (with the intent), inspect, map with the suggested mapping, validate. */
function di4Stage(string $type, string $csv, array $options = [], ?User $user = null): EmployeeImport
{
    $user ??= auth()->user();
    $path = EmployeeImports::directory().'/'.uniqid('di4-').'.csv';
    Storage::disk('local')->put($path, $csv);
    $service = ImportTypes::service($type);
    $import = $service->inspect(ImportTypes::register($type, $path, 'di4.csv', $user, $options));

    return $service->validate($service->map($import, $import->mapping ?? [], ['mode' => $options['mode'] ?? 'both']));
}

/** Approve and run a validated upload as the uploader. */
function di4Run(EmployeeImport $import, ?User $user = null): EmployeeImport
{
    $user ??= auth()->user();
    $service = ImportTypes::service($import->type);

    return $service->run($service->approve($import->refresh(), $user, 'DI-4 test'), $user);
}

/** @return Collection<int, EmployeeImportRow> rows by row number */
function di4Rows(EmployeeImport $import): Collection
{
    return $import->rows()->orderBy('row_number')->get()->keyBy('row_number');
}

/** An active employee on the STANDARD structure with a PAN, paid from 2026-01-01. */
function di4Employee(float $ctc = 600000, array $permissions = ['task.view']): Employee
{
    $employee = salariedEmployee($ctc, $permissions, from: '2026-01-01');
    EmployeeStatutoryDetail::query()->updateOrCreate(['employee_id' => $employee->id], ['pan' => 'ABCDE'.random_int(1000, 9999).'F']);

    return $employee->refresh();
}

function di4Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'DI-4 test');
}

/** Calculate, approve and finalize a month for the company (preparer and approver are different people). */
function di4Finalize(int $month, User $preparer, User $approver): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open(payrollCompany(), 2026, $month, $preparer), $preparer);

    return $runs->finalize($runs->approve($runs->validate($run), $approver, 'ok'), $approver);
}

/** A second active structure with STANDARD's lines (prepared and approved by two people). */
function di4Structure(string $code, string $from = '2026-01-01'): SalaryStructure
{
    $tenant = app(TenantContext::class)->current();
    $preparer = tenantUser($tenant, ['compensation.configure']);
    $approver = tenantUser($tenant, ['compensation.approve']);
    $structures = app(CompensationStructures::class);
    $standard = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail()->versionOn($from);
    $rows = $standard->components()->get()->map(fn ($c) => $c->only(['salary_component_id', 'formula_override', 'pay_nature', 'frequency', 'sort_order']))->all();
    $structure = $structures->create(['name' => "{$code} pay group", 'code' => $code, 'effective_from' => $from, 'components' => $rows], $preparer);
    $version = $structure->versions()->firstOrFail();
    $structures->submit($version, $preparer);
    $structures->approve($version, $approver);

    return $structure->refresh();
}
