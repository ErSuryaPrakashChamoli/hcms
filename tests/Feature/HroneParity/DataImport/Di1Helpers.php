<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use Illuminate\Support\Facades\Storage;

/* Data import DI-1 test helpers (Core, Project Management, Integration uploads). Global: prefixed di1. */

/** Stage a CSV as an upload of a type the way the Data import page does: register → inspect → map (as suggested) → validate. */
function di1Stage(string $type, string $csv, string $mode = 'both', array $defaults = []): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid('di1', true).'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = ImportTypes::register($type, $path, 'upload.csv', auth()->user(), ['mode' => $mode] + ($defaults !== [] ? ['defaults' => $defaults] : []));
    $service = ImportTypes::service($type);
    $service->inspect($import);
    $import->refresh();
    $service->map($import, $import->mapping ?? []);

    return $service->validate($import->refresh());
}

/** Approve and run a checked upload. */
function di1Run(EmployeeImport $import): EmployeeImport
{
    $service = ImportTypes::service($import->type);
    $service->approve($import, auth()->user(), 'Go-live data');

    return $service->run($import->refresh(), auth()->user());
}

/** Stage a process's own sample file (what "Download sample file" gives) with the process's defaults. */
function di1StageSample(string $processKey, string $mode = 'both'): EmployeeImport
{
    $process = ImportCatalogue::find($processKey);

    return di1Stage($process['type'], ImportCatalogue::csv($process, true), $mode, $process['defaults'] ?? []);
}

/** @return array<int, array{action: string, errors: ?array, status: ?string, result: ?string, match: ?array}> row number => what happened */
function di1Rows(EmployeeImport $import): array
{
    return $import->rows()->orderBy('row_number')->get()->mapWithKeys(fn ($row) => [$row->row_number => [
        'action' => $row->action, 'errors' => $row->errors, 'status' => $row->status, 'result' => $row->result, 'match' => $row->match,
    ]])->all();
}

function di1Company(string $code = 'ACME', string $name = 'Acme Industries'): Company
{
    return Company::factory()->create(['code' => $code, 'name' => $name]);
}

function di1Hire(Company $company, string $code, string $first = 'Asha'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => $code], ['company_id' => $company->id]);
}
