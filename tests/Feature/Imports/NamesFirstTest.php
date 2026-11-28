<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\Workbooks\WorkbookCatalogue;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Platform\Services\FeatureFlags;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
| Names first (owner decision 2026-11-26): uploads take the names people see, not codes. A reference cell may hold a
| name (or a code, or "Name (CODE)"); a master upload's own code may be left blank. Files made from the old templates,
| with their "… code" headings, still map to the same fields.
*/

beforeEach(function () {
    $this->travelTo('2026-11-26 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->asha = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP1'], ['company_id' => $this->mk->id]);
});

function nfUpload(string $type, string $csv, $user, string $mode = 'both'): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid($type).'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = ImportTypes::register($type, $path, "{$type}.csv", $user, ['mode' => $mode]);
    $service = ImportTypes::service($type);
    $service->inspect($import);
    $import->refresh();
    $service->map($import, $import->mapping);

    return $service->validate($import->refresh());
}

function nfRun(EmployeeImport $import, $user): EmployeeImport
{
    $service = ImportTypes::service($import->type);
    $service->approve($import->refresh(), $user, 'test');

    return $service->run($import->refresh(), $user);
}

/** @return array<int, array<string, mixed>> */
function nfRows(EmployeeImport $import): array
{
    return $import->rows()->orderBy('row_number')->get()->keyBy('row_number')->map(fn ($r) => $r->only(['action', 'status', 'errors', 'match', 'result']))->all();
}

it('still maps every column heading of the old templates to the same field', function () {
    $legacy = require base_path('tests/Fixtures/Imports/legacy-headers.php');
    foreach ($legacy as $type => $headers) {
        if (! array_key_exists($type, ImportTypes::labels())) {
            continue;
        }
        $mapping = ImportTypes::service($type)->suggestMapping(array_keys($headers));
        foreach ($headers as $header => $field) {
            expect($mapping[$header] ?? null)->toBe($field, "{$type}: the old heading '{$header}' no longer maps to {$field}");
        }
    }
});

it('heads reference columns with names, not codes, in every upload format', function () {
    // Codes that are the row's own key or real-world values (a PIN, a dialling code, a ledger code) stay codes.
    $codes = ['Code', 'Employee code', 'New employee code', 'Postal code', 'Current PIN / postal code', 'Permanent PIN / postal code',
        'Personal phone country code', 'Work phone country code', 'Device code', 'JV code', 'Accounting system code', 'Vendor code',
        'Calendar code', 'Asset tag', 'State code', 'District code', 'ISO region code', 'Insurer code',
        // 2026-11-27: the Addresses and Emergency contacts uploads (a PIN and a dialling code, as above).
        'PIN / postal code', 'Phone country code'];
    foreach (array_keys(ImportTypes::labels()) as $type) {
        $class = ImportTypes::serviceClass($type);
        if (! method_exists($class, 'fields')) {
            continue;
        }
        foreach ($class::fields() as $field => [$label]) {
            if (preg_match('/\bcodes?\b/i', $label) === 1) {
                expect(in_array($label, $codes, true))->toBeTrue("{$type}: the column '{$label}' ({$field}) still asks for a code");
            }
        }
    }
});

it('reads a leave type by its name in a row upload and stores the same entry as its code would', function () {
    $earned = LeaveType::query()->where('code', 'EL')->firstOrFail();
    $import = nfUpload('leave_balances', "Employee code,Leave type,Days,Leave year\nEMP1,{$earned->name},12,2026\n", $this->admin);

    expect(nfRows($import)[1]['action'])->toBe('create')->and($import->error_count)->toBe(0);
    nfRun($import, $this->admin);
    expect(LeaveLedgerEntry::query()->where('employee_id', $this->asha->id)->where('leave_type_id', $earned->id)->where('accrual_key', 'opening-2026')->exists())->toBeTrue();
});

it('still takes a leave code under the old heading', function () {
    $import = nfUpload('leave_balances', "Employee code,Leave code,Days,Leave year\nEMP1,EL,12,2026\n", $this->admin);

    expect(nfRows($import)[1]['action'])->toBe('create');
});

it('refuses a leave type name that two leave types share, and says how to choose', function () {
    LeaveType::query()->where('code', 'CL')->firstOrFail()->update(['name' => 'Flexi leave']);
    LeaveType::query()->where('code', 'SL')->firstOrFail()->update(['name' => 'Flexi leave']);
    $import = nfUpload('leave_balances', "Employee code,Leave type,Days,Leave year\nEMP1,Flexi leave,2,2026\n", $this->admin);
    $row = nfRows($import)[1];

    expect($row['action'])->toBe('error')
        ->and(implode(' ', $row['errors']))->toContain('Flexi leave')->toContain('CL')->toContain('SL')
        ->and(implode(' ', $row['errors']))->not->toContain('does not exist');
});

it('adds a department from its name alone, with a code made from the name', function () {
    $import = nfUpload('departments', "Name,Company\nSales & Marketing,Markedge Technologies\n", $this->admin);
    expect(nfRows($import)[1]['action'])->toBe('create');
    nfRun($import, $this->admin);

    $department = Department::query()->where('name', 'Sales & Marketing')->firstOrFail();
    expect($department->code)->toBe('SALES_AND_MARKETING')->and($department->company_id)->toBe($this->mk->id);
});

it('changes the department of that name when the code is left blank, for the row\'s company', function () {
    $mkFinance = Department::factory()->create(['name' => 'Finance', 'code' => 'FIN', 'company_id' => $this->mk->id]);
    $feFinance = Department::factory()->create(['name' => 'Finance', 'code' => 'FIN-FE', 'company_id' => $this->fe->id]);

    $import = nfUpload('departments', "Code,Name,Company,Description\n,Finance,FynnEdge Advisory,Accounts\n", $this->admin);
    expect(nfRows($import)[1]['action'])->toBe('update')->and(nfRows($import)[1]['match']['id'])->toBe($feFinance->id);
    nfRun($import, $this->admin);

    expect($feFinance->refresh()->description)->toBe('Accounts')->and($mkFinance->refresh()->description)->not->toBe('Accounts')
        ->and(Department::query()->where('name', 'Finance')->count())->toBe(2);
});

it('asks for the code when a department name is shared and no company says which', function () {
    Department::factory()->create(['name' => 'Finance', 'code' => 'FIN', 'company_id' => $this->mk->id]);
    Department::factory()->create(['name' => 'Finance', 'code' => 'FIN-FE', 'company_id' => $this->fe->id]);

    $row = nfRows(nfUpload('departments', "Name,Description\nFinance,Accounts\n", $this->admin))[1];

    expect($row['action'])->toBe('error')->and($row['errors'][0])->toContain('FIN')->toContain('FIN-FE')->toContain('Code');
});

it('refuses two rows that add the same name', function () {
    $rows = nfRows(nfUpload('departments', "Name,Company\nOperations,Markedge Technologies\noperations,Markedge Technologies\n", $this->admin));

    expect($rows[1]['action'])->toBe('create')->and($rows[2]['action'])->toBe('error')->and($rows[2]['errors'][0])->toContain('row 1');
});

it('gives a second new name that makes the same code the next free code', function () {
    nfRun(nfUpload('departments', "Name\nR&D\nR and D!\n", $this->admin), $this->admin);

    expect(Department::query()->orderBy('id')->pluck('code', 'name')->only(['R&D', 'R and D!'])->all())->toBe(['R&D' => 'R_AND_D', 'R and D!' => 'R_AND_D_2']);
});

it('places a sub department under a parent named in an earlier row of the same file', function () {
    Department::factory()->create(['name' => 'Engineering', 'code' => 'ENG', 'company_id' => $this->mk->id]);
    $import = nfUpload('sub_departments', "Name,Parent department\nPlatform,Engineering\nPlatform SRE,Platform\n", $this->admin);
    expect(collect(nfRows($import))->pluck('action')->all())->toBe(['create', 'create']);
    nfRun($import, $this->admin);

    $platform = Department::query()->where('name', 'Platform')->firstOrFail();
    expect(Department::query()->where('name', 'Platform SRE')->value('parent_department_id'))->toBe($platform->id);
});

it('names in later worksheets the records an earlier worksheet adds without codes', function () {
    config(['peopleos.imports.workbooks.queue' => false]);
    $local = tempnam(sys_get_temp_dir(), 'nf').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($local);
    $sheets = [
        'Departments' => [['Code', 'Name', 'Company'], ['', 'Stores', 'Markedge Technologies']],
        'Sub departments' => [['Code', 'Name', 'Parent department'], ['', 'Stores north', 'Stores']],
        'Levels' => [['Code', 'Name'], ['', 'Senior']],
        'Grades' => [['Code', 'Name', 'Level'], ['', 'G7', 'Senior']],
    ];
    $first = true;
    foreach ($sheets as $title => $rows) {
        ($first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent())->setName($title);
        $first = false;
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
    }
    $writer->close();
    $path = EmployeeImports::directory().'/'.uniqid('nf').'.xlsx';
    Storage::disk('local')->put($path, file_get_contents($local));
    @unlink($local);
    $import = app(WorkbookImports::class)->check(ImportTypes::register(WorkbookCatalogue::type('organisation'), $path, 'workbook.xlsx', $this->admin, ['mode' => 'both']));

    expect($import->status)->toBe('validated')->and((int) $import->error_count)->toBe(0);
    app(WorkbookImports::class)->approve($import, $this->admin, 'Structure by name');
    app(WorkbookImports::class)->start($import->refresh(), $this->admin);

    $stores = Department::query()->where('name', 'Stores')->firstOrFail();
    expect($import->refresh()->status)->toBe('imported')
        ->and($stores->code)->toBe('STORES')->and($stores->company_id)->toBe($this->mk->id)
        ->and(Department::query()->where('name', 'Stores north')->value('parent_department_id'))->toBe($stores->id)
        ->and(Grade::query()->where('name', 'G7')->value('level_id'))->toBe(Level::query()->where('name', 'Senior')->value('id'));
});
