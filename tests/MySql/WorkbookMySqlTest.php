<?php

use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\Workbooks\WorkbookCatalogue;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Models\Team;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\Platform\Services\FeatureFlags;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
| Master uploads on MySQL (production acceptance): the workbook columns, the JSON queries of the preview, name
| resolution, applicability and the tree on a real MySQL server, and the volume figures there. SQLite never enforces
| column lengths and plans queries differently. Off unless pointed at a disposable database whose name contains
| "workbook" (PEOPLEOS_MYSQL_WORKBOOK_DB); the server comes from DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD.
*/

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_WORKBOOK_DB', '');
    if ($database === '' || ! str_contains($database, 'workbook')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_WORKBOOK_DB to a disposable MySQL database (name containing "workbook").');
    }
    config(['database.connections.workbook' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'workbook', 'queue.default' => 'sync']);
    DB::purge('workbook');
    if (! ($GLOBALS['peopleos_workbook_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'workbook', '--force' => true]);
        $GLOBALS['peopleos_workbook_migrated'] = true;
    }
    $this->travelTo('2026-11-23 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local', 'peopleos.imports.workbooks.queue' => false]);
    $this->tenant = provisionTenant('Workbook '.uniqid());
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'MySQL acceptance');
});

/** @param array<string, iterable<list<string|int|null>>> $sheets title => rows (first row the header) */
function mwWorkbook(string $key, array $sheets, $user, string $mode = 'both'): EmployeeImport
{
    $local = tempnam(sys_get_temp_dir(), 'mw').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($local);
    $first = true;
    foreach ($sheets as $title => $rows) {
        ($first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent())->setName($title);
        $first = false;
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
    }
    $writer->close();
    $path = EmployeeImports::directory().'/'.uniqid('mw').'.xlsx';
    Storage::disk('local')->put($path, fopen($local, 'rb'));
    @unlink($local);

    return ImportTypes::register(WorkbookCatalogue::type($key), $path, 'workbook.xlsx', $user, ['mode' => $mode]);
}

/** Check and run a workbook, timing each part. @return array<string, mixed> */
function mwRun(EmployeeImport $import, $user): array
{
    $service = app(WorkbookImports::class);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $t = hrtime(true);
    $service->check($import);
    $check = (hrtime(true) - $t) / 1e9;
    $checkPeak = memory_get_peak_usage(true);
    $service->approve($import->refresh(), $user, 'MySQL');
    memory_reset_peak_usage();
    $t = hrtime(true);
    $service->start($import->refresh(), $user);

    return ['import' => $import->refresh(), 'check_s' => round($check, 2), 'run_s' => round((hrtime(true) - $t) / 1e9, 2),
        'check_peak_mb' => round($checkPeak / 1048576), 'run_peak_mb' => round(memory_get_peak_usage(true) / 1048576)];
}

it('creates the workbook columns and runs an organisation workbook with names, applicability, blocked rows and the tree', function () {
    expect(Schema::hasColumns('employee_imports', ['parent_id', 'sheet', 'stage']))->toBeTrue();

    $import = mwWorkbook('organisation', [
        'Companies' => [['Code', 'Name', 'Country code', 'State', 'City'], ['MKT', 'Markedge Technologies', 'IN', 'MH', 'Pune'], ['FYN', 'FynnEdge Advisory', 'IN', 'KA', 'Bengaluru']],
        'Departments' => [['Code', 'Name', 'Company code'], ['ENG', 'Engineering', 'Markedge Technologies'], ['OPS', 'Operations', 'MKT']],
        'Sub departments' => [['Code', 'Name', 'Parent department code'], ['ENG-QA', 'Quality', 'Engineering'], ['BAD-1', 'Broken', 'NO-SUCH']],
        'Teams' => [['Code', 'Name', 'Company code'], ['REL', 'Release', 'MKT']],
        'Designations' => [['Code', 'Name', 'Applies to'], ['SE', 'Software engineer', 'MKT; FynnEdge Advisory'], ['ANL', 'Analyst', 'FYN']],
        'Unit tree' => [['Unit type', 'Unit code', 'Parent type', 'Parent code'], ['company', 'MKT', '', ''], ['department', 'ENG', 'company', 'MKT'],
            ['team', 'REL', 'department', 'ENG'], ['department', 'BAD-1', 'department', 'ENG']],
    ], $this->admin);
    $m = mwRun($import, $this->admin);

    $sheets = collect(WorkbookImports::sheetSummaries($m['import']))->keyBy('title');
    $service = app(EntityApplicability::class);
    $node = fn (string $class, string $code) => OrganisationNode::query()->where('nodeable_type', $class)->where('nodeable_id', $class::query()->where('code', $code)->value('id'))->first();
    $mkt = Company::query()->where('code', 'MKT')->value('id');
    $fyn = Company::query()->where('code', 'FYN')->value('id');
    expect($m['import']->status)->toBe('imported')
        ->and(Department::query()->where('code', 'ENG')->value('company_id'))->toBe($mkt)
        ->and(Department::query()->where('code', 'ENG-QA')->value('parent_department_id'))->toBe(Department::query()->where('code', 'ENG')->value('id'))
        ->and($service->reach(Designation::query()->where('code', 'SE')->first()))->toEqualCanonicalizing([$mkt, $fyn])
        ->and($service->reach(Designation::query()->where('code', 'ANL')->first()))->toBe([$fyn])
        ->and($node(Team::class, 'REL')->parent_id)->toBe($node(Department::class, 'ENG')->id)
        // The JSON queries of the preview work on MySQL: a blocked row is counted as blocked.
        ->and($sheets['Unit tree']['blocked'])->toBe(1)
        ->and($sheets['Unit tree']['outcome'])->toBe('partial')
        ->and($sheets['Sub departments']['outcome'])->toBe('partial')
        ->and($sheets['Companies']['outcome'])->toBe('completed');
});

it('hires employees with managers further down the sheet and adds their family once they exist', function () {
    Company::factory()->create(['code' => 'ACME', 'name' => 'Acme']);
    $import = mwWorkbook('employees', [
        'Employees' => [['Employee code', 'First name', 'Last name', 'Joining date', 'Company code', 'Manager employee code'],
            ['E1', 'Asha', 'Rao', '2026-04-01', 'Acme', 'E2'], ['E2', 'Vikram', 'Shah', '2026-01-01', 'ACME', '']],
        'Family members' => [['Employee code', 'Relation', 'Name'], ['E1', 'mother', 'Meera Rao']],
    ], $this->admin, 'new');
    $m = mwRun($import, $this->admin);
    $e1 = Employee::query()->where('employee_code', 'E1')->firstOrFail();
    expect($m['import']->status)->toBe('imported')
        ->and($e1->reportingRelationships()->where('type', 'line')->value('manager_id'))->toBe(Employee::query()->where('employee_code', 'E2')->value('id'))
        ->and(PersonFamilyMember::query()->where('person_id', $e1->person_id)->where('name', 'Meera Rao')->exists())->toBeTrue();
});

it('measures checking and running workbooks at volume on MySQL', function (int $n) {
    if (blank(env('WORKBOOK_VOLUME'))) {
        $this->markTestSkipped('Set WORKBOOK_VOLUME=1000,10000 to measure on MySQL.');
    }
    $out = (string) env('WORKBOOK_VOLUME_OUT', sys_get_temp_dir().'/workbook-volume-mysql.jsonl');
    $report = fn (array $r) => file_put_contents($out, json_encode(['db' => 'mysql'] + array_diff_key($r, ['import' => 1]))."\n", FILE_APPEND);

    $org = mwRun(mwWorkbook('organisation', [
        'Companies' => [['Code', 'Name', 'Country code', 'State', 'City'], ['VOL', 'Volume Co', 'IN', 'MH', 'Pune']],
        'Departments' => [['Code', 'Name', 'Company code'], ...(function () use ($n) {
            for ($i = 1; $i <= $n; $i++) {
                yield ['D'.$i, 'Department '.$i, $i % 2 === 0 ? 'Volume Co' : 'VOL'];
            }
        })()],
    ], $this->admin), $this->admin);
    $report(['scenario' => 'organisation (half by company name)', 'rows' => $n + 1] + $org);
    expect($org['import']->status)->toBe('imported')->and((int) $org['import']->failure_count)->toBe(0)
        ->and(Department::query()->where('company_id', Company::query()->where('code', 'VOL')->value('id'))->count())->toBe($n);

    $hires = min($n, (int) env('WORKBOOK_VOLUME_EMPLOYEES_MAX', 1000));
    $emp = mwRun(mwWorkbook('employees', [
        'Employees' => [['Employee code', 'First name', 'Last name', 'Joining date', 'Company code', 'Manager employee code'], ...(function () use ($hires) {
            for ($i = 1; $i <= $hires; $i++) {
                yield ['V'.$i, 'First'.$i, 'Last'.$i, '2026-04-01', 'VOL', $i % 10 === 0 ? '' : 'V'.(intdiv($i, 10) * 10 + 10 <= $hires ? intdiv($i, 10) * 10 + 10 : '')];
            }
        })()],
        'Family members' => [['Employee code', 'Relation', 'Name'], ...(function () use ($hires) {
            for ($i = 1; $i <= $hires; $i++) {
                yield ['V'.$i, 'mother', 'Mother of '.$i];
            }
        })()],
    ], $this->admin, 'new'), $this->admin);
    $report(['scenario' => 'employees + family', 'rows' => 2 * $hires] + $emp);
    expect($emp['import']->status)->toBe('imported')->and(Employee::query()->where('employee_code', 'like', 'V%')->count())->toBe($hires);
})->with(fn () => array_map('intval', array_filter(explode(',', (string) env('WORKBOOK_VOLUME', '1000')))));
