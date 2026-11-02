<?php

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use Illuminate\Support\Facades\Storage;

/*
 | Data import DI-35 test helpers (Time Office, Performance, Engage, Asset uploads), shared by the three DI-35 test files.
 | Top-level functions are global across the suite: every name starts with di35.
 */

/** Stage a CSV as an upload of the type the way the Data import page does (register → inspect → map → validate). */
function di35Stage(string $type, string $csv, string $mode = 'both', array $options = []): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid('di35', true).'.csv';
    Storage::disk('local')->put($path, $csv);
    $service = ImportTypes::service($type);
    $import = ImportTypes::register($type, $path, 'upload.csv', auth()->user(), ['mode' => $mode] + $options);
    $service->inspect($import);
    $import->refresh();
    $service->map($import, $import->mapping ?? []);

    return $service->validate($import->refresh());
}

/** Approve and run a validated upload. */
function di35Run(EmployeeImport $import): EmployeeImport
{
    $service = ImportTypes::service($import->type);
    $service->approve($import, auth()->user(), 'Data import');

    return $service->run($import->refresh(), auth()->user());
}

/** Stage a process's own sample file (its "Download sample file"), with the process's defaults as the page passes them. */
function di35StageSample(string $process, string $mode = 'both'): EmployeeImport
{
    $found = ImportCatalogue::find($process);

    return di35Stage($found['type'], ImportCatalogue::csv($found, true), $mode, array_filter(['process' => $process, 'defaults' => $found['defaults'] ?? null]));
}

/** The row errors of a validated upload, by row number. @return array<int, list<string>> */
function di35Errors(EmployeeImport $import): array
{
    return $import->rows()->where('action', 'error')->orderBy('row_number')->get()->mapWithKeys(fn ($row) => [$row->row_number => $row->errors ?? []])->all();
}

/** The result text of each run row, by row number. @return array<int, string> */
function di35Results(EmployeeImport $import): array
{
    return $import->rows()->orderBy('row_number')->get()->mapWithKeys(fn ($row) => [$row->row_number => (string) $row->result])->all();
}
