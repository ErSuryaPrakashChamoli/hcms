<?php

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\Workbooks\WorkbookCatalogue;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Platform\Services\FeatureFlags;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
| Master uploads at volume: how long checking and running a workbook takes, and the memory it needs, for 100, 1,000 and
| 10,000 rows. Off by default (minutes of work): WORKBOOK_VOLUME=100,1000,10000 build-tools/pest.sh tests/Feature/Imports/
| Workbooks/WorkbookVolumeTest.php. Results are appended as JSON lines to WORKBOOK_VOLUME_OUT (also against MySQL with DB_CONNECTION=mysql
| DB_DATABASE=<scratch db>).
*/

beforeEach(function () {
    if (blank(env('WORKBOOK_VOLUME'))) {
        $this->markTestSkipped('Set WORKBOOK_VOLUME=100,1000,10000 to measure master uploads at volume.');
    }
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local', 'peopleos.imports.workbooks.queue' => false]);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Volume test');
});

/** Write a workbook of rows streamed from a generator; return its path on the fake disk. */
function volumeWorkbook(array $sheets): string
{
    $local = tempnam(sys_get_temp_dir(), 'vol').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($local);
    $first = true;
    foreach ($sheets as $title => [$header, $rows]) {
        ($first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent())->setName($title);
        $first = false;
        $writer->addRow(Row::fromValues($header));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
    }
    $writer->close();
    $path = EmployeeImports::directory().'/'.uniqid('vol').'.xlsx';
    Storage::disk('local')->put($path, fopen($local, 'rb'));
    @unlink($local);

    return $path;
}

/** One result line, appended to WORKBOOK_VOLUME_OUT (default: the system temp folder). */
function volumeReport(array $result): void
{
    $line = json_encode(['db' => config('database.default')] + array_diff_key($result, ['import' => 1]));
    file_put_contents((string) env('WORKBOOK_VOLUME_OUT', sys_get_temp_dir().'/workbook-volume.jsonl'), $line."\n", FILE_APPEND);
}

/** Check and run a workbook, timing each part. @return array<string, mixed> */
function measureWorkbook(string $key, string $path, $user): array
{
    $service = app(WorkbookImports::class);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $t = hrtime(true);
    $import = ImportTypes::register(WorkbookCatalogue::type($key), $path, 'volume.xlsx', $user, ['mode' => 'both']);
    $service->check($import);
    $checked = (hrtime(true) - $t) / 1e9;
    $checkPeak = memory_get_peak_usage(true);
    $import->refresh();
    $service->approve($import, $user, 'volume');
    memory_reset_peak_usage();
    $t = hrtime(true);
    $service->start($import->refresh(), $user);
    $ran = (hrtime(true) - $t) / 1e9;

    return ['import' => $import->refresh(), 'check_s' => round($checked, 2), 'run_s' => round($ran, 2), 'check_peak_mb' => round($checkPeak / 1048576), 'run_peak_mb' => round(memory_get_peak_usage(true) / 1048576)];
}

it('checks and runs an organisation workbook at volume', function (int $n) {
    $path = volumeWorkbook([
        'Companies' => [['Code', 'Name', 'Country code', 'State', 'City'], [['VOL', 'Volume Co', 'IN', 'MH', 'Pune']]],
        'Departments' => [['Code', 'Name', 'Company code'], (function () use ($n) {
            for ($i = 1; $i <= $n; $i++) {
                yield ['D'.$i, 'Department '.$i, 'VOL'];
            }
        })()],
    ]);
    $m = measureWorkbook('organisation', $path, $this->hr);
    volumeReport(['scenario' => 'organisation', 'rows' => $n + 1] + $m);

    expect($m['import']->status)->toBe('imported')
        ->and((int) $m['import']->failure_count)->toBe(0)
        ->and(Department::query()->where('company_id', Company::query()->where('code', 'VOL')->value('id'))->count())->toBe($n);
})->with(fn () => array_map('intval', array_filter(explode(',', (string) env('WORKBOOK_VOLUME', '100')))));

it('checks and runs an employees workbook at volume (new hires, managers in the same sheet, family waiting for them)', function (int $n) {
    Company::factory()->create(['code' => 'VOLC']);
    $path = volumeWorkbook([
        'Employees' => [['Employee code', 'First name', 'Last name', 'Joining date', 'Company code', 'Manager employee code'], (function () use ($n) {
            for ($i = 1; $i <= $n; $i++) {
                // Every tenth person manages the next nine; managers come after their reports in the file.
                yield ['V'.$i, 'First'.$i, 'Last'.$i, '2026-04-01', 'VOLC', $i % 10 === 0 ? '' : 'V'.(intdiv($i, 10) * 10 + 10 <= $n ? intdiv($i, 10) * 10 + 10 : '')];
            }
        })()],
        'Family members' => [['Employee code', 'Relation', 'Name'], (function () use ($n) {
            for ($i = 1; $i <= $n; $i++) {
                yield ['V'.$i, 'mother', 'Mother of '.$i];
            }
        })()],
    ]);
    // The Employees worksheet is the employee upload, which takes up to peopleos.people.import_max_rows (5,000) people
    // per file. Above that the check refuses the worksheet, and the workbook cannot be approved: nobody is hired.
    $limit = (int) config('peopleos.people.import_max_rows', 5000);
    if ($n > $limit) {
        $import = app(WorkbookImports::class)->check(ImportTypes::register(WorkbookCatalogue::type('employees'), $path, 'volume.xlsx', $this->hr, ['mode' => 'both']));
        $sheets = collect(WorkbookImports::sheetSummaries($import))->keyBy('title');
        volumeReport(['scenario' => 'employees', 'rows' => 2 * $n, 'refused' => "over the {$limit}-row employee upload limit"]);

        expect(WorkbookImports::blockedSheets($import))->toContain('Employees')
            ->and($import->options['sheets']['Employees']['problem'])->toBe("The file has more than {$limit} rows; split it.")
            ->and(fn () => app(WorkbookImports::class)->approve($import->refresh(), $this->hr, 'volume'))->toThrow(RuntimeException::class, 'Some worksheets cannot be imported (Employees)')
            ->and(Employee::query()->where('employee_code', 'like', 'V%')->count())->toBe(0);

        return;
    }
    $m = measureWorkbook('employees', $path, $this->hr);
    volumeReport(['scenario' => 'employees', 'rows' => 2 * $n] + $m);

    expect($m['import']->status)->toBe('imported')
        ->and(Employee::query()->where('employee_code', 'like', 'V%')->count())->toBe($n)
        ->and((int) EmployeeImport::query()->where('parent_id', $m['import']->id)->where('sheet', 'Family members')->value('create_count'))->toBe($n);
})->with(fn () => array_map('intval', array_filter(explode(',', (string) env('WORKBOOK_VOLUME_EMPLOYEES', env('WORKBOOK_VOLUME', '100'))))));
