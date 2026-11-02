<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/* Data import DI-2 (Workforce uploads): helpers shared by the DI-2 test files (prefixed di2: helper names are global). */

function di2Hire(string $code, string $first, array $position = [], ?int $userId = null): Employee
{
    $company = Company::query()->orderBy('id')->first() ?? Company::factory()->create();

    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Rao'],
        array_filter(['joining_date' => '2025-01-01', 'employee_code' => $code, 'user_id' => $userId]), $position + ['company_id' => $company->id]);
}

/** Stage a CSV as an upload of the type the way the screens do: register → inspect → map (the suggested columns) → validate. */
function di2Stage(string $type, string $csv, string $mode = 'both', array $options = [], ?User $as = null): EmployeeImport
{
    $as ??= auth()->user();
    $path = 'tenants/'.app(TenantContext::class)->current()->id.'/imports/'.uniqid('di2', true).'.csv';
    Storage::disk('local')->put($path, $csv);
    $service = ImportTypes::service($type);
    $import = $service->inspect(ImportTypes::register($type, $path, $type.'.csv', $as, ['mode' => $mode] + $options));

    return $service->validate($service->map($import, $import->mapping ?? [], ['mode' => $mode]));
}

/** Approve and run a validated upload. */
function di2Run(EmployeeImport $import, ?User $as = null): EmployeeImport
{
    $as ??= auth()->user();
    $service = ImportTypes::service($import->type);

    return $service->run($service->approve($import, $as, 'DI-2 test'), $as);
}

function di2Row(EmployeeImport $import, int $number): EmployeeImportRow
{
    return $import->rows()->where('row_number', $number)->firstOrFail();
}

/** The errors (or the note) of a row, as one line. */
function di2Errors(EmployeeImport $import, int $number): string
{
    $row = di2Row($import, $number);

    return implode(' ', $row->errors ?? []) ?: (string) ($row->match['reason'] ?? '');
}

/** @return list<string> the actions of the rows, in row order */
function di2Actions(EmployeeImport $import): array
{
    return $import->rows()->orderBy('row_number')->pluck('action')->all();
}

/** @return list<string> the timeline titles of an employee */
function di2Timeline(Employee $employee): array
{
    return EmployeeTimelineEntry::query()->where('employee_id', $employee->getKey())->orderBy('id')->pluck('title')->all();
}
