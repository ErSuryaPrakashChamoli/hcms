<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\Workbooks\DependencyPlanner;
use App\Domain\Employment\Imports\Workbooks\PlannedReferences;
use App\Domain\Employment\Imports\Workbooks\WorkbookCatalogue;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Employment\Imports\Workbooks\WorkbookReader;
use App\Domain\Employment\Imports\Workbooks\WorkbookWriter;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\DataImport;
use App\Filament\Pages\MasterImport;
use App\Filament\Pages\RecentUploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/* Master uploads: one Excel workbook of several worksheets, checked and run in dependency order. */

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local', 'peopleos.imports.workbooks.queue' => false]);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
});

/**
 * An .xlsx on the fake private disk with these worksheets (title => rows, the first row the header).
 *
 * @param  array<string, list<list<string|int|null>>>  $sheets
 */
function workbookOnDisk(array $sheets): string
{
    $local = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($local);
    $first = true;
    foreach ($sheets as $title => $rows) {
        $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($title);
        $first = false;
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
    }
    $writer->close();
    $path = EmployeeImports::directory().'/'.uniqid('wb').'.xlsx';
    Storage::disk('local')->put($path, file_get_contents($local));
    @unlink($local);

    return $path;
}

/** Upload, read and check a workbook as the given person. */
function checkedWorkbook(string $key, array $sheets, $user, string $mode = 'both'): EmployeeImport
{
    $import = ImportTypes::register(WorkbookCatalogue::type($key), workbookOnDisk($sheets), 'go-live.xlsx', $user, ['mode' => $mode]);
    $service = app(WorkbookImports::class);
    $service->inspect($import);

    return $service->validate($import->refresh());
}

/** @return array<string, array<string, mixed>> worksheet title => summary */
function workbookSheets(EmployeeImport $import): array
{
    return collect(WorkbookImports::sheetSummaries($import->refresh()))->keyBy('title')->all();
}

it('declares workbooks whose worksheets are built uploads, fit Excel and never depend on each other in a circle', function () {
    foreach (WorkbookCatalogue::WORKBOOKS as $key => $workbook) {
        expect(strlen(WorkbookCatalogue::type($key)))->toBeLessThanOrEqual(32);
        $sheets = WorkbookCatalogue::sheets($key);
        foreach ($sheets as $title => $sheet) {
            expect(mb_strlen($title))->toBeLessThanOrEqual(31, $title)
                ->and(ImportCatalogue::built($sheet['process']))->toBeTrue("{$key} › {$title} is not a built upload")
                ->and(in_array($title, WorkbookCatalogue::GUIDE_SHEETS, true))->toBeFalse();
            $fields = ImportCatalogue::fields($sheet['process']);
            foreach ([...array_values($sheet['provides']), ...array_merge([], ...array_values($sheet['needs']))] as $field) {
                expect(array_key_exists($field, $fields))->toBeTrue("{$key} › {$title} names a field its upload does not have: {$field}");
            }
            foreach ([...array_keys($sheet['provides']), ...array_keys($sheet['needs'])] as $kind) {
                expect(WorkbookCatalogue::KINDS)->toHaveKey($kind);
            }
        }
        $plan = DependencyPlanner::plan($sheets);
        expect($plan['order'])->toHaveCount(count($sheets));
    }
});

it('orders worksheets by what they need, keeping the workbook order otherwise, and refuses a circle', function () {
    $plan = DependencyPlanner::plan([
        'Designations' => ['needs' => ['level' => ['level_code'], 'department' => ['department_code']], 'provides' => ['designation' => 'code']],
        'Departments' => ['needs' => ['company' => ['company_code']], 'provides' => ['department' => 'code']],
        'Levels' => ['provides' => ['level' => 'code']],
        'Companies' => ['provides' => ['company' => 'code']],
        'Sub departments' => ['needs' => ['department' => ['parent_code']], 'provides' => ['department' => 'code']],
    ]);
    expect($plan['order'])->toBe(['Levels', 'Companies', 'Departments', 'Sub departments', 'Designations'])
        ->and($plan['stages'])->toMatchArray(['Levels' => 1, 'Companies' => 1, 'Departments' => 2, 'Designations' => 4, 'Sub departments' => 3])
        ->and($plan['after']['Designations'])->toEqualCanonicalizing(['Levels', 'Departments', 'Sub departments']);

    expect(fn () => DependencyPlanner::plan(['A' => ['needs' => ['x' => ['f']], 'provides' => ['y' => 'c']], 'B' => ['needs' => ['y' => ['f']], 'provides' => ['x' => 'c']]]))
        ->toThrow(RuntimeException::class, 'in a circle');
});

it('builds a whole organisation from one workbook: later worksheets use codes earlier ones add', function () {
    $import = checkedWorkbook('organisation', [
        'Read me' => [['ignored guidance']],
        // Deliberately out of order: the engine works the order out.
        'Designations' => [['Code', 'Name', 'Level code', 'Grade code', 'Department code'], ['SE', 'Software engineer', 'LV1', 'GR1', 'ENG']],
        'Departments' => [['Code', 'Name', 'Company code', 'Cost centre code'], ['ENG', 'Engineering', 'MKT', 'CC-1'], [], ['FIN', 'Finance', 'MKT', '']],
        'Sub departments' => [['Code', 'Name', 'Parent department code'], ['ENG-QA', 'Quality', 'ENG']],
        'Companies' => [['Code', 'Name', 'Country code', 'State', 'City'], ['MKT', 'Markedge Technologies', 'IN', 'MH', 'Pune'], ['FYN', 'FynnEdge Advisory', 'IN', 'KA', 'Bengaluru']],
        'Cost centres' => [['Code', 'Name', 'Company code'], ['CC-1', 'Product', 'MKT']],
        'Levels' => [['Code', 'Name', 'Rank'], ['LV1', 'Associate', 1]],
        'Grades' => [['Code', 'Name', 'Level code'], ['GR1', 'Grade 1', 'LV1']],
        'Branches' => [['Code', 'Name', 'Company code', 'City', 'State code'], ['PUNE', 'Pune office', 'MKT', 'Pune', 'MH']],
    ], $this->hr);

    $sheets = workbookSheets($import);
    expect($import->status)->toBe('validated')
        ->and((int) $import->error_count)->toBe(0)
        ->and(array_keys($sheets))->toBe(['Companies', 'Cost centres', 'Branches', 'Levels', 'Departments', 'Grades', 'Sub departments', 'Designations'])
        ->and($sheets['Departments']['create'])->toBe(2)
        ->and($sheets['Designations']['after'])->toEqualCanonicalizing(['Companies', 'Departments', 'Sub departments', 'Levels', 'Grades'])
        // The blank row between ENG and FIN is left out, and every row keeps its worksheet row number.
        ->and($sheets['Departments']['rows'])->toBe(2)
        ->and(EmployeeImport::query()->find($sheets['Departments']['child'])->rows()->orderBy('row_number')->get()->map(fn ($r) => $r->data[WorkbookImports::ROW_COLUMN])->all())->toBe(['2', '4'])
        // Nothing is written before the run.
        ->and(Company::query()->where('code', 'MKT')->exists())->toBeFalse();

    $service = app(WorkbookImports::class);
    $service->approve($import, $this->hr, 'Go-live organisation');
    $service->start($import->refresh(), $this->hr);
    $import->refresh();

    $mkt = Company::query()->where('code', 'MKT')->firstOrFail();
    $eng = Department::query()->where('code', 'ENG')->firstOrFail();
    expect($import->status)->toBe('imported')
        ->and((int) $import->failure_count)->toBe(0)
        ->and((int) $import->create_count)->toBe(10)
        ->and($mkt->state_code)->toBe('MH')
        ->and($mkt->currency)->toBe('INR')
        ->and($mkt->timezone)->toBe('Asia/Kolkata')
        ->and((int) $eng->company_id)->toBe((int) $mkt->id)
        ->and((int) $eng->cost_centre_id)->toBe((int) CostCentre::query()->where('code', 'CC-1')->value('id'))
        ->and((int) Department::query()->where('code', 'ENG-QA')->value('parent_department_id'))->toBe((int) $eng->id)
        ->and((int) Grade::query()->where('code', 'GR1')->value('level_id'))->toBe((int) Level::query()->where('code', 'LV1')->value('id'))
        ->and(Designation::query()->where('code', 'SE')->first())->department_id->toBe($eng->id)
        ->and((int) Location::query()->where('code', 'PUNE')->value('company_id'))->toBe((int) $mkt->id)
        // Every worksheet is an ordinary upload of its type, run and audited as such.
        ->and(EmployeeImport::query()->where('parent_id', $import->id)->pluck('status')->unique()->values()->all())->toBe(['imported'])
        ->and($import->operation_id)->not->toBeNull();
});

it('names the row with errors when a later worksheet depends on it, and lets the rest through', function () {
    $import = checkedWorkbook('organisation', [
        'Companies' => [['Code', 'Name', 'Country code', 'State', 'City'], ['BAD CODE', 'Broken Co', 'IN'], ['OKC', 'Fine Co', 'IN', 'KA', 'Bengaluru']],
        'Departments' => [['Code', 'Name', 'Company code'], ['OPS', 'Operations', 'BAD CODE'], ['HR', 'People', 'OKC'], ['XX', 'Nowhere', 'NOPE']],
    ], $this->hr);

    $rows = EmployeeImport::query()->where('parent_id', $import->id)->where('sheet', 'Departments')->firstOrFail()->rows()->orderBy('row_number')->get();
    expect($rows[0]->action)->toBe('error')
        ->and(implode(' ', $rows[0]->errors))->toContain('row 2 of Companies, which has errors')
        ->and($rows[1]->action)->toBe('create')
        ->and($rows[2]->action)->toBe('error')
        ->and(implode(' ', $rows[2]->errors))->toContain("Company 'NOPE' does not exist");

    // The error workbook lists each worksheet's rows with errors, with the row number people see in Excel.
    $path = app(WorkbookWriter::class)->errors($import, sys_get_temp_dir().'/'.uniqid('err').'.xlsx');
    $read = (new WorkbookReader)->read($path, sys_get_temp_dir(), 100);
    expect(array_keys($read))->toContain('Companies', 'Departments');
    $departments = iterator_to_array(Reader::from($read['Departments']['csv'])->getRecords(), false);
    expect(array_column($departments, 0))->toBe(['Sheet row', '2', '4']);

    $service = app(WorkbookImports::class);
    $service->approve($import, $this->hr, 'Partial');
    $service->start($import->refresh(), $this->hr);
    expect(Company::query()->where('code', 'OKC')->exists())->toBeTrue()
        ->and(Department::query()->where('code', 'HR')->exists())->toBeTrue()
        ->and(Department::query()->whereIn('code', ['OPS', 'XX'])->exists())->toBeFalse();
});

it('hires employees with managers further down the same worksheet and adds their family once they exist', function () {
    $company = Company::factory()->create(['code' => 'ACME']);
    $import = checkedWorkbook('employees', [
        'Employees' => [
            ['Employee code', 'First name', 'Last name', 'Joining date', 'Company code', 'Manager employee code'],
            ['E1', 'Asha', 'Rao', '2026-04-01', 'ACME', 'E2'],
            ['E2', 'Vikram', 'Shah', '2026-01-01', 'ACME', ''],
        ],
        'Family members' => [['Employee code', 'Relation', 'Name'], ['E1', 'mother', 'Meera Rao'], ['', 'father', 'Missing Code']],
    ], $this->hr, 'new');

    $sheets = workbookSheets($import);
    expect((int) $import->error_count)->toBe(1)
        ->and($sheets['Employees']['create'])->toBe(2)
        ->and($sheets['Employees']['errors'])->toBe(0)
        // Family of a new hire waits until the hire exists; a row missing a required column is an error at once.
        ->and($sheets['Family members']['waiting'])->toBe(1)
        ->and($sheets['Family members']['errors'])->toBe(1)
        ->and($import->options['waiting'])->toBe(1);

    $service = app(WorkbookImports::class);
    $service->approve($import, $this->hr, 'Joiners');
    $service->start($import->refresh(), $this->hr);

    $e1 = Employee::query()->where('employee_code', 'E1')->firstOrFail();
    $e2 = Employee::query()->where('employee_code', 'E2')->firstOrFail();
    expect($import->refresh()->status)->toBe('imported')
        ->and((int) $e1->currentPosition()->value('company_id'))->toBe((int) $company->id)
        ->and($e1->reportingRelationships()->where('type', 'line')->value('manager_id'))->toBe($e2->id)
        ->and(PersonFamilyMember::query()->where('person_id', $e1->person_id)->where('name', 'Meera Rao')->exists())->toBeTrue()
        ->and(EmployeeImport::query()->where('parent_id', $import->id)->where('sheet', 'Family members')->value('status'))->toBe('imported');
});

it('approves a workbook under the strictest approval rule of the uploads it holds', function () {
    app(SettingsRepository::class)->set('imports.approval_mode', 'other_person');
    app(SettingsRepository::class)->set('imports.approval_types', [BankAccountImports::TYPE]);
    $company = Company::factory()->create(['code' => 'ACME']);
    app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP1'], ['company_id' => $company->id]);

    $withBank = checkedWorkbook('employees', [
        'Education' => [['Employee code', 'Qualification'], ['EMP1', 'B.Tech']],
        'Bank accounts' => [['Employee code', 'Account holder name', 'Bank name', 'Account number', 'IFSC'], ['EMP1', 'Asha Rao', 'HDFC Bank', '50100123456789', 'HDFC0000123']],
    ], $this->hr);
    expect(ImportGovernance::modeFor($withBank))->toBe('other_person')
        ->and(fn () => app(WorkbookImports::class)->approve($withBank, $this->hr, 'self'))->toThrow(RuntimeException::class, 'maker-checker');

    $withBank->refresh();
    $checker = tenantUser($this->tenant, ['*']);
    expect(WorkbookImports::maySee($checker, $withBank))->toBeTrue();
    app(WorkbookImports::class)->approve($withBank, $checker, 'Checked');
    expect($withBank->refresh()->status)->toBe('approved');

    // Without bank details the same workbook keeps the default rule (the uploader approves).
    $plain = checkedWorkbook('employees', ['Education' => [['Employee code', 'Qualification'], ['EMP1', 'M.Tech']]], $this->hr);
    expect(ImportGovernance::modeFor($plain))->toBe('self');
});

it('keeps a worksheet from being approved or dropped on its own', function () {
    $import = checkedWorkbook('organisation', ['Levels' => [['Code', 'Name'], ['RZ9', 'Nine']]], $this->hr);
    $child = EmployeeImport::query()->where('parent_id', $import->id)->firstOrFail();
    expect(fn () => ImportTypes::service($child->type)->approve($child, $this->hr, 'alone'))->toThrow(RuntimeException::class, 'approved and run with its master upload');

    app(WorkbookImports::class)->approve($import, $this->hr, 'ok');
    expect(fn () => ImportTypes::service($child->type)->discard($child->refresh(), 'drop'))->toThrow(RuntimeException::class, 'cannot be discarded on its own');
});

it('refuses worksheets the uploader may not make and columns it cannot match, and reports sheets it does not know', function () {
    $limited = tenantUser($this->tenant, ['employee.import', 'organisation.create', 'organisation.view']);
    $import = checkedWorkbook('organisation', [
        'Departments' => [['Code', 'Name'], ['OPS', 'Operations']],
        'Levels' => [['Code', 'Name'], ['RZ1', 'One']],
        'Branches' => [['Code', 'Label'], ['B1', 'No name column']],
        'Notes' => [['Anything'], ['stray']],
    ], $limited);

    $sheets = workbookSheets($import);
    expect($sheets['Levels']['problem'])->toContain('You may not make Levels uploads')
        ->and($sheets['Branches']['problem'])->toContain('The columns do not match')
        ->and($sheets['Departments']['problem'])->toBeNull()
        ->and($import->options['warnings'][0])->toContain("Worksheet 'Notes' is not part of this master upload")
        ->and(fn () => app(WorkbookImports::class)->approve($import, $limited, 'x'))->toThrow(RuntimeException::class, 'Some worksheets cannot be imported');
});

it('writes a template whose worksheets read back as the uploads expect', function () {
    $path = app(WorkbookWriter::class)->template('organisation', $this->hr, sample: false, path: sys_get_temp_dir().'/'.uniqid('tpl').'.xlsx');
    $read = (new WorkbookReader)->read($path, sys_get_temp_dir(), 5000);
    expect(array_keys($read))->toContain('Read me', 'Columns', 'Companies', 'Designations')
        ->and($read['Companies']['rows'])->toBe(0);

    // Each header maps onto its upload's fields without any matching by hand.
    $companies = ImportTypes::service('companies');
    $mapping = $companies->suggestMapping($read['Companies']['headers']);
    expect(array_values($mapping))->toContain('code', 'name', 'country_code');

    $sample = app(WorkbookWriter::class)->template('organisation', $this->hr, sample: true, path: sys_get_temp_dir().'/'.uniqid('smp').'.xlsx');
    expect((new WorkbookReader)->read($sample, sys_get_temp_dir(), 5000)['Companies']['rows'])->toBeGreaterThan(0);
});

it('leaves individual uploads exactly as they were: no planned references outside a workbook', function () {
    expect(app(PlannedReferences::class)->active())->toBeFalse()
        ->and(app(PlannedReferences::class)->deferral(['employee_code' => 'E1']))->toBeNull();

    $path = EmployeeImports::directory().'/dept.csv';
    Storage::disk('local')->put($path, "Code,Name,Company code\nOPS,Operations,NOPE\n");
    $import = ImportTypes::register('departments', $path, 'dept.csv', $this->hr);
    $service = ImportTypes::service('departments');
    $service->inspect($import);
    $service->map($import->refresh(), $import->mapping);
    $service->validate($import->refresh());
    expect($import->rows()->first()->errors)->toBe(["Company 'NOPE' does not exist."]);
});

it('never runs a row twice when a run is resumed', function () {
    $import = checkedWorkbook('organisation', ['Levels' => [['Code', 'Name'], ['RZ1', 'One'], ['RZ2', 'Two']]], $this->hr);
    $child = EmployeeImport::query()->where('parent_id', $import->id)->firstOrFail();
    $service = app(WorkbookImports::class);
    $service->approve($import, $this->hr, 'ok');
    $import->refresh()->update(['status' => 'importing']);
    // A first attempt that stopped after its first row: approved and running, row 1 already in.
    ImportTypes::service('levels')->approve($child, $this->hr, 'first attempt');
    $child->refresh()->update(['status' => 'importing']);
    Level::query()->create(['code' => 'RZ1', 'name' => 'One', 'status' => 'active']);
    $child->rows()->where('row_number', 1)->update(['status' => 'imported', 'result' => 'Created RZ1']);

    $service->execute($import->refresh());

    expect($import->refresh()->status)->toBe('imported')
        ->and(Level::query()->where('code', 'RZ1')->count())->toBe(1)
        ->and(Level::query()->where('code', 'RZ2')->exists())->toBeTrue()
        ->and((int) $child->refresh()->create_count)->toBe(2);
});

it('lists a workbook once in Recent uploads and only for people who may see all of it', function () {
    $import = checkedWorkbook('organisation', ['Levels' => [['Code', 'Name'], ['RZ1', 'One']]], $this->hr);
    $visible = RecentUploads::visibleUploads()->pluck('id')->all();
    expect($visible)->toContain($import->id)
        ->and($visible)->not->toContain(EmployeeImport::query()->where('parent_id', $import->id)->value('id'));

    $other = tenantUser($this->tenant, ['*']);
    expect(WorkbookImports::maySee($other, $import))->toBeFalse();
});

it('shows the master uploads above every single upload on Data import, each with its template', function () {
    $organisation = collect((new DataImport)->masterWorkbooks())->firstWhere('key', 'organisation');
    $hidden = count($organisation['sheets']) - 4;

    Livewire\Livewire::test(DataImport::class)
        ->assertSee('Preferred imports')->assertSee('Organisation Master')->assertSee('Employee Master')->assertSee('Accounting Codes (JV)')
        // A card shows four worksheets; "+N more" opens the rest, which are already on the page.
        ->assertSeeHtml('aria-controls="mu-sheets-organisation"')->assertSee("+{$hidden} more")
        ->assertSee(end($organisation['sheets'])['title'])
        // The single uploads all stay below.
        ->assertSee('Select process')
        ->call('downloadWorkbookTemplate', 'organisation')->assertFileDownloaded('organisation-master-template.xlsx');

    // Someone who may make no upload of a workbook does not see its card.
    $narrow = tenantUser($this->tenant, ['employee.import', 'leave.adjust', 'leave.view']);
    $this->actingAs($narrow);
    $cards = (new DataImport)->masterWorkbooks();
    // Employee uploads need only employee.import (as on their own screen); leave balances need leave.adjust.
    $cards = collect($cards)->keyBy('key');
    expect($cards->keys()->all())->toBe(['employees', 'time_office'])
        ->and(array_column($cards['employees']['sheets'], 'title'))->toBe(['Employees'])
        ->and(array_column($cards['time_office']['sheets'], 'title'))->toBe(['Opening leave balances', 'Comp-off credits']);
});

it('takes a workbook from upload to import on the master uploads page', function () {
    $local = tempnam(sys_get_temp_dir(), 'up').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($local);
    $writer->getCurrentSheet()->setName('Levels');
    $writer->addRow(Row::fromValues(['Code', 'Name', 'Rank']));
    $writer->addRow(Row::fromValues(['PG1', 'Page level', 3]));
    $writer->close();
    $file = UploadedFile::fake()->createWithContent('org.xlsx', file_get_contents($local));

    $page = Livewire\Livewire::test(MasterImport::class, ['workbook' => 'organisation'])
        ->assertSee('What the workbook holds, in the order it runs')
        ->fillForm(['mode' => 'new', 'file' => $file])
        ->call('uploadAndCheck');
    $import = EmployeeImport::query()->where('type', 'workbook_organisation')->latest('id')->firstOrFail();
    $page->assertRedirect(MasterImport::getUrl(['upload' => $import->id]));
    expect($import->status)->toBe('validated')->and($import->options['mode'])->toBe('new');

    Livewire\Livewire::test(MasterImport::class, ['upload' => $import->id])
        ->assertSee('Every row checked out')->assertSee('Approve and import 1 row(s)')
        ->fillForm(['reason' => 'Go-live'])->call('approve')
        ->assertSee('Import finished');
    expect(Level::query()->where('code', 'PG1')->value('rank'))->toBe(3);

    // Only people who may see the whole workbook open it.
    $this->actingAs(tenantUser($this->tenant, ['*']));
    Livewire\Livewire::test(MasterImport::class, ['upload' => $import->id])->assertForbidden();
});
