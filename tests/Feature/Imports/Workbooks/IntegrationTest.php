<?php

use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Cloning\CloneRequest;
use App\Domain\Configuration\Cloning\ConfigurationCloner;
use App\Domain\Configuration\Cloning\Destination;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\Workbooks\WorkbookCatalogue;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Models\ProfitCentre;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Models\WorkMode;
use App\Domain\Organisation\Resolution\ReferenceMappings;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Pages\MasterImport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
| Integration of master uploads with name resolution (MasterResolver), entity applicability (EntityApplicability) and
| the R1 upload rules ((none), optional business-unit company), plus the remaining organisation masters and the tree.
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local', 'peopleos.imports.workbooks.queue' => false]);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
});

/** Stage, match and check a single upload from CSV text, as the given person. */
function miUpload(string $type, string $csv, $user, string $mode = 'both'): EmployeeImport
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

function miRun(EmployeeImport $import, $user): EmployeeImport
{
    $service = ImportTypes::service($import->type);
    $service->approve($import->refresh(), $user, 'test');

    return $service->run($import->refresh(), $user);
}

/** @return array<int, array<string, mixed>> row number => row */
function miRows(EmployeeImport $import): array
{
    return $import->rows()->orderBy('row_number')->get()->keyBy('row_number')->map(fn ($r) => $r->only(['action', 'status', 'errors', 'match', 'result']))->all();
}

/** Upload, read and check a workbook. @param array<string, list<list<string|int|null>>> $sheets */
function miWorkbook(string $key, array $sheets, $user, string $mode = 'both'): EmployeeImport
{
    $local = tempnam(sys_get_temp_dir(), 'mi').'.xlsx';
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
    $path = EmployeeImports::directory().'/'.uniqid('mi').'.xlsx';
    Storage::disk('local')->put($path, file_get_contents($local));
    @unlink($local);
    $import = ImportTypes::register(WorkbookCatalogue::type($key), $path, 'workbook.xlsx', $user, ['mode' => $mode]);

    return app(WorkbookImports::class)->check($import);
}

it('resolves references by code exactly as before, and by exact name or "Name (CODE)" when a cell is not a code', function () {
    CostCentre::factory()->create(['name' => 'People costs', 'code' => 'CC1', 'company_id' => $this->mk->id]);
    $import = miUpload('departments', "Code,Name,Company code,Cost centre code\n"
        ."D1,Operations,Markedge Technologies,CC1\n"     // company by name
        ."D2,People,  markedge   TECHNOLOGIES ,People costs (CC1)\n" // spacing and case ignored; name + code agree
        ."D3,Finance,mk,\n"                               // a code, any case: as always
        ."D4,Nowhere,NOPE,\n", $this->admin);             // unknown: the message unknown references always had (labelled by name since names first)
    $rows = miRows($import);
    expect($rows[1]['action'])->toBe('create')->and($rows[2]['action'])->toBe('create')->and($rows[3]['action'])->toBe('create')
        ->and($rows[4]['errors'])->toBe(["Company 'NOPE' does not exist."]);

    miRun($import, $this->admin);
    $cc = CostCentre::query()->where('code', 'CC1')->value('id');
    expect(Department::query()->where('code', 'D1')->first()->only(['company_id', 'cost_centre_id']))->toBe(['company_id' => $this->mk->id, 'cost_centre_id' => $cc])
        ->and(Department::query()->where('code', 'D2')->first()->only(['company_id', 'cost_centre_id']))->toBe(['company_id' => $this->mk->id, 'cost_centre_id' => $cc])
        ->and(Department::query()->where('code', 'D3')->value('company_id'))->toBe($this->mk->id);
});

it('never chooses between namesakes or near misses, refuses a name and code that disagree, and sends them to the review', function () {
    Level::query()->create(['code' => 'S1', 'name' => 'Senior', 'status' => 'active']);
    Level::query()->create(['code' => 'S2', 'name' => 'Senior', 'status' => 'active']);
    Level::query()->create(['code' => 'LA', 'name' => 'Associate', 'status' => 'active']);
    $import = miUpload('grades', "Code,Name,Level code\nGX,Gx,Senior\nGY,Gy,Asociate\nGZ,Gz,Senior (LA)\nGW,Gw,Associate\n", $this->admin);
    $rows = miRows($import);
    expect(implode(' ', $rows[1]['errors']))->toContain('matches 2 records')
        ->and(implode(' ', $rows[2]['errors']))->toContain('Did you mean')
        ->and(implode(' ', $rows[3]['errors']))->toContain("but the name says 'Senior'")
        ->and($rows[4]['action'])->toBe('create');

    // The upload keeps what needs a decision, for the review panel on the upload's screen.
    $entries = collect($import->refresh()->options['references']['entries'] ?? []);
    expect($entries->pluck('status')->unique()->sort()->values()->all())->toContain('ambiguous', 'suggested', 'conflict');
    expect(Level::query()->find(Level::query()->where('code', 'S1')->value('id'))->code)->toBe('S1');
});

it('keeps organisations apart: another organisation\'s names and codes are never matched or named', function () {
    $other = provisionTenant('Bravo');
    app(TenantContext::class)->runAs($other, function () {
        Company::factory()->create(['name' => 'Bravo Corp', 'code' => 'BRV']);
        CostCentre::factory()->create(['name' => 'Bravo costs', 'code' => 'BRCC']);
    });
    actAsTenant($this->tenant);
    $import = miUpload('departments', "Code,Name,Company code,Cost centre code\nX1,One,Bravo Corp,\nX2,Two,BRV,\nX3,Three,MK,Bravo costs\nX4,Four,MK,Bravo costs (BRCC)\n", $this->admin);
    $rows = miRows($import);
    expect($rows[1]['errors'])->toBe(["Company 'Bravo Corp' does not exist."])
        ->and($rows[2]['errors'])->toBe(["Company 'BRV' does not exist."])
        ->and(implode(' ', $rows[3]['errors']))->toContain('does not exist')->not->toContain('BRCC')
        // Only the person's own text comes back: nothing from the other organisation is matched or named.
        ->and($rows[4]['errors'])->toBe(["Cost centre 'Bravo costs (BRCC)' does not exist."]);
    // The review lists what the person typed, never a record of the other organisation as a candidate.
    $entries = collect($import->refresh()->options['references']['entries'] ?? []);
    expect($entries->pluck('candidates')->flatten(1)->all())->toBe([])
        ->and($entries->pluck('status')->unique()->values()->all())->toBe(['unmatched']);
});

it('only uses records that apply to the company a row names, by code and by name', function () {
    CostCentre::factory()->create(['name' => 'FynnEdge costs', 'code' => 'FECC', 'company_id' => $this->fe->id]);
    $import = miUpload('departments', "Code,Name,Company code,Cost centre code\nA1,One,MK,FECC\nA2,Two,FE,FECC\nA3,Three,MK,FynnEdge costs\nA4,Four,,FECC\n", $this->admin);
    $rows = miRows($import);
    expect(implode(' ', $rows[1]['errors']))->toContain('is not available to Markedge Technologies')
        ->and($rows[2]['action'])->toBe('create')
        ->and(implode(' ', $rows[3]['errors']))->toContain('FynnEdge')
        // A row that names no company keeps the earlier meaning: no company, no check.
        ->and($rows[4]['action'])->toBe('create');
});

it('sets where a master applies through the same services as its screen: all, all now, one company, several', function () {
    $import = miUpload('designations', "Code,Name,Applies to,Future companies\nDS1,One,all,no\nDS2,Two,MK,\nDS3,Three,MK; FynnEdge Advisory,\nDS4,Four,,\nDS5,Five,all,\n", $this->admin);
    expect(collect(miRows($import))->pluck('action')->unique()->values()->all())->toBe(['create']);
    miRun($import, $this->admin);

    $service = app(EntityApplicability::class);
    $state = fn (string $code) => $service->state(Designation::query()->where('code', $code)->firstOrFail());
    expect($state('DS1'))->toMatchArray(['explicit' => true, 'mode' => 'all', 'include_future' => false])
        ->and($service->reach(Designation::query()->where('code', 'DS2')->first()))->toBe([$this->mk->id])
        ->and($service->reach(Designation::query()->where('code', 'DS3')->first()))->toEqualCanonicalizing([$this->mk->id, $this->fe->id])
        // Blank, or "all" with future companies, means what a new record means anyway: nothing is written.
        ->and($state('DS4')['explicit'])->toBeFalse()
        ->and($state('DS5')['explicit'])->toBeFalse();

    // A later upload changes only where it applies: an update, written as the screen writes it.
    $again = miUpload('designations', "Code,Name,Applies to\nDS2,Two,MK; FE\nDS3,Three,MK; FE\n", $this->admin);
    expect(miRows($again)[1]['action'])->toBe('update')->and(miRows($again)[2]['action'])->toBe('skip');
    miRun($again, $this->admin);
    expect($service->reach(Designation::query()->where('code', 'DS2')->first()))->toEqualCanonicalizing([$this->mk->id, $this->fe->id]);
});

it('refuses applicability a company-limited uploader may not set, and records they may not change', function () {
    $shared = Designation::factory()->create(['code' => 'SHARED', 'name' => 'Shared']);
    $limited = tenantUser($this->tenant, ['employee.import', 'people_setup.create', 'people_setup.update', 'people_setup.view', 'configuration.share']);
    app(AccessScopes::class)->assign($limited, ['company' => [$this->mk->id]]);
    $this->actingAs($limited);
    $import = miUpload('designations', "Code,Name,Applies to\nLM1,Mine,MK\nLM2,Both,MK; FE\nSHARED,Renamed,\n", $limited);
    $rows = miRows($import);
    expect($rows[1]['action'])->toBe('create')
        ->and($rows[2]['errors'])->toContain('FynnEdge Advisory is outside your access.')
        ->and(implode(' ', $rows[3]['errors']))->toContain('also used by companies outside your access');
    expect($shared->fresh()->name)->toBe('Shared');
});

it('keeps a shared master shared and a clone independent when an upload changes the source', function () {
    $source = Designation::factory()->create(['code' => 'ENG', 'name' => 'Engineer']);
    $copy = app(ConfigurationCloner::class)->execute(new CloneRequest('designation', $source->id, [new Destination($this->fe->id, 'FynnEdge engineer', 'ENG_FE')],
        reason: 'Second entity', acknowledged: true), $this->admin)->copies[0];

    miRun(miUpload('designations', "Code,Name,Description\nENG,Software engineer,Builds things\n", $this->admin), $this->admin);

    expect($source->fresh()->name)->toBe('Software engineer')
        ->and(app(EntityApplicability::class)->reach($source->fresh()))->toBeNull()
        ->and($copy->fresh()->name)->toBe('FynnEdge engineer')
        ->and(app(EntityApplicability::class)->reach($copy->fresh()))->toBe([$this->fe->id]);
});

it('removes an optional link with (none), keeps it when blank, and refuses (none) on required links', function () {
    $level = Level::query()->create(['code' => 'LZ5', 'name' => 'Level five', 'status' => 'active']);
    Designation::factory()->create(['code' => 'DZ', 'name' => 'Dz', 'level_id' => $level->id]);
    Designation::factory()->create(['code' => 'DK', 'name' => 'Dk', 'level_id' => $level->id]);
    $import = miUpload('designations', "Code,Name,Level code\nDZ,Dz, (NONE) \nDK,Dk,\n", $this->admin);
    $rows = miRows($import);
    expect($rows[1]['action'])->toBe('update')->and($rows[1]['match']['removed'])->toBe(['Level: LZ5 → (removed)'])
        ->and($rows[2]['action'])->toBe('skip')
        // (none) is never sent to the name resolver.
        ->and($import->refresh()->options['references'] ?? null)->toBeNull();
    miRun($import, $this->admin);
    expect(Designation::query()->where('code', 'DZ')->value('level_id'))->toBeNull()
        ->and(Designation::query()->where('code', 'DK')->value('level_id'))->toBe($level->id);

    $branches = miUpload('locations', "Code,Name,Company code\nBX,Bx,(none)\n", $this->admin);
    expect(miRows($branches)[1]['errors'])->toContain('Company is required here; (none) cannot be used.');
    Department::factory()->create(['company_id' => $this->mk->id, 'code' => 'PAR', 'name' => 'Parent']);
    $subs = miUpload('sub_departments', "Code,Name,Parent department code\nSUB,Sub,(none)\n", $this->admin);
    expect(miRows($subs)[1]['errors'])->toContain('Parent department is required here; (none) cannot be used.');

    // Business units: the company is optional now (as on the form); an unknown one is still refused.
    $units = miUpload('business_units', "Code,Name,Company code\nNOCO,No company,\nBAD,Bad,NOPE\n", $this->admin);
    expect(miRows($units)[1]['action'])->toBe('create')->and(miRows($units)[2]['errors'])->toBe(["Company 'NOPE' does not exist."]);
    miRun($units, $this->admin);
    expect(BusinessUnit::query()->where('code', 'NOCO')->value('company_id'))->toBeNull();
});

it('adds the remaining organisation masters and places units in the tree from one workbook, in dependency order', function () {
    $import = miWorkbook('organisation', [
        'Unit tree' => [['Unit type', 'Unit code', 'Parent type', 'Parent code'], ['company', 'MK', '', ''], ['division', 'DIV1', 'company', 'MK'],
            ['department', 'DEP1', 'division', 'Retail'], ['team', 'T1', 'department', 'DEP1']],
        'Teams' => [['Code', 'Name', 'Company code'], ['T1', 'Payroll ops', 'MK']],
        'Divisions' => [['Code', 'Name', 'Company code'], ['DIV1', 'Retail', 'Markedge Technologies']],
        'Departments' => [['Code', 'Name', 'Company code'], ['DEP1', 'Stores', 'MK']],
        'Profit centres' => [['Code', 'Name', 'Company code', 'Applies to'], ['PC1', 'Consulting', 'MK', 'MK']],
        'Job families' => [['Code', 'Name', 'Applies to'], ['JF1', 'Technology', 'FE']],
        'Employment types' => [['Code', 'Name'], ['ET1', 'Permanent']],
        'Employee categories' => [['Code', 'Name'], ['EC1', 'Staff']],
        'Work modes' => [['Code', 'Name'], ['WM1', 'Hybrid']],
    ], $this->admin);

    $sheets = collect(WorkbookImports::sheetSummaries($import))->keyBy('title');
    expect($import->status)->toBe('validated')->and((int) $import->error_count)->toBe(0)
        ->and(array_key_last($sheets->all()))->toBe('Unit tree')
        ->and($sheets['Unit tree']['waiting'])->toBe(3)
        ->and(array_keys($sheets['Unit tree']['waits_for']))->toEqualCanonicalizing(['Divisions', 'Departments', 'Teams']);

    app(WorkbookImports::class)->approve($import, $this->admin, 'Structure');
    app(WorkbookImports::class)->start($import->refresh(), $this->admin);
    $node = fn (string $class, string $code) => OrganisationNode::query()->where('nodeable_type', $class)->where('nodeable_id', $class::query()->where('code', $code)->value('id'))->first();

    expect($import->refresh()->status)->toBe('imported')
        ->and(collect(WorkbookImports::sheetSummaries($import))->pluck('outcome')->unique()->values()->all())->toBe(['completed'])
        ->and(Division::query()->where('code', 'DIV1')->value('company_id'))->toBe($this->mk->id)
        ->and(JobFamily::query()->where('code', 'JF1')->exists())->toBeTrue()
        ->and(app(EntityApplicability::class)->reach(JobFamily::query()->where('code', 'JF1')->first()))->toBe([$this->fe->id])
        ->and(EmploymentType::query()->where('code', 'ET1')->exists() && EmployeeCategory::query()->where('code', 'EC1')->exists() && WorkMode::query()->where('code', 'WM1')->exists())->toBeTrue()
        ->and(ProfitCentre::query()->where('code', 'PC1')->value('company_id'))->toBe($this->mk->id)
        ->and($node(Division::class, 'DIV1')->parent_id)->toBe($node(Company::class, 'MK')->id)
        ->and($node(Department::class, 'DEP1')->parent_id)->toBe($node(Division::class, 'DIV1')->id)
        ->and($node(Team::class, 'T1')->parent_id)->toBe($node(Department::class, 'DEP1')->id);

    // The tree's own rules hold: a company cannot sit under a team.
    $bad = miUpload('organisation_tree', "Unit type,Unit code,Parent type,Parent code\ncompany,FE,team,T1\n", $this->admin);
    expect(implode(' ', miRows($bad)[1]['errors']))->toContain('cannot sit under a Team');
});

it('shows what each row waits for or is blocked by, and how each worksheet ended', function () {
    $import = miWorkbook('employees', [
        'Employees' => [['Employee code', 'First name', 'Last name', 'Joining date', 'Company code'], ['E1', 'Asha', 'Rao', '2026-04-01', 'MK'], ['E2', 'Ravi', '', '2026-04-01', 'MK']],
        'Family members' => [['Employee code', 'Relation', 'Name'], ['E1', 'mother', 'Meera'], ['E2', 'father', 'Mohan']],
    ], $this->admin, 'new');

    $family = collect(WorkbookImports::sheetSummaries($import))->keyBy('title')['Family members'];
    expect($family['waiting'])->toBe(1)->and($family['blocked'])->toBe(1)->and($family['other_errors'])->toBe(0)
        ->and($family['waits_for'])->toBe(['Employees' => ['waiting' => 1, 'blocked' => 1]]);
    $rows = EmployeeImport::query()->where('parent_id', $import->id)->where('sheet', 'Family members')->firstOrFail()->rows()->orderBy('row_number')->get();
    expect(WorkbookImports::rowStatus($rows[0]))->toBe('Waits for an earlier worksheet')
        ->and(WorkbookImports::rowStatus($rows[1]))->toBe('Blocked by an earlier worksheet')
        ->and($rows[1]->match['depends_on'][0])->toMatchArray(['state' => 'blocked', 'sheet' => 'Employees', 'row' => 3, 'key' => 'E2']);

    app(WorkbookImports::class)->approve($import, $this->admin, 'Partial');
    app(WorkbookImports::class)->start($import->refresh(), $this->admin);
    $outcomes = collect(WorkbookImports::sheetSummaries($import->refresh()))->pluck('outcome', 'title')->all();
    expect($outcomes)->toBe(['Employees' => 'partial', 'Family members' => 'partial'])
        ->and(Employee::query()->where('employee_code', 'E1')->exists())->toBeTrue()
        ->and(Employee::query()->where('employee_code', 'E2')->exists())->toBeFalse();
});

it('resolves an employee upload\'s organisation records and manager by name, within the row\'s company', function () {
    $boss = app(HireEmployeeAction::class)->handle(['first_name' => 'Vikram', 'last_name' => 'Shah'], ['joining_date' => '2025-01-01', 'employee_code' => 'B1', 'work_email' => 'vikram@mk.test'], ['company_id' => $this->mk->id]);
    Department::factory()->create(['company_id' => $this->mk->id, 'code' => 'ENG', 'name' => 'Engineering']);
    $feOnly = Level::query()->create(['code' => 'FL', 'name' => 'FynnEdge level', 'status' => 'active']);
    app(EntityApplicability::class)->apply($feOnly, ApplicabilitySelection::single($this->fe->id), $this->admin);

    $import = miUpload('employees', "Employee code,First name,Last name,Joining date,Company code,Department code,Level code,Manager employee code\n"
        ."N1,Asha,Rao,2026-04-01,Markedge Technologies,Engineering,,vikram@mk.test\n"
        ."N2,Ravi,Kumar,2026-04-01,MK,,FL,\n", $this->admin, 'new');
    $rows = miRows($import);
    expect($rows[1]['action'])->toBe('create')
        ->and(implode(' ', $rows[2]['errors']))->toContain('is not available to Markedge Technologies');

    miRun($import, $this->admin);
    $n1 = Employee::query()->where('employee_code', 'N1')->firstOrFail();
    expect((int) $n1->currentPosition()->value('company_id'))->toBe($this->mk->id)
        ->and((int) $n1->currentPosition()->value('department_id'))->toBe((int) Department::query()->where('code', 'ENG')->value('id'))
        ->and($n1->reportingRelationships()->where('type', 'line')->value('manager_id'))->toBe($boss->id);
});

it('adds nothing the second time the same workbook is imported', function () {
    $sheets = ['Levels' => [['Code', 'Name'], ['LV9', 'Nine']], 'Grades' => [['Code', 'Name', 'Level code'], ['GR9', 'Nine', 'Nine']]];
    $first = miWorkbook('organisation', $sheets, $this->admin);
    app(WorkbookImports::class)->approve($first, $this->admin, 'once');
    app(WorkbookImports::class)->start($first->refresh(), $this->admin);
    expect(Level::query()->where('code', 'LV9')->count())->toBe(1);

    $second = miWorkbook('organisation', $sheets, $this->admin);
    expect((int) $second->create_count + (int) $second->update_count)->toBe(0)->and((int) $second->skip_count)->toBe(2)
        ->and(fn () => app(WorkbookImports::class)->approve($second, $this->admin, 'twice'))->toThrow(RuntimeException::class, 'Nothing to import');
});

it('shows the name review on the master upload page, and a decision there counts when the workbook is checked again', function () {
    $s1 = Level::query()->create(['code' => 'SN1', 'name' => 'Senior', 'status' => 'active']);
    Level::query()->create(['code' => 'SN2', 'name' => 'Senior', 'status' => 'active']);
    $import = miWorkbook('organisation', ['Grades' => [['Code', 'Name', 'Level code'], ['GX', 'Gx', 'Senior']]], $this->admin);

    $page = Livewire\Livewire::test(MasterImport::class, ['upload' => $import->id])
        ->assertSee('Names in the workbook')->assertSee('Senior')
        ->call('showRows', 'Grades')->assertSee('matches 2 records');

    // The reviewer says "Senior" means SN1 in this upload (as the panel does), and the panel announces it.
    app(ReferenceMappings::class)->map($this->admin, 'level', 'Senior', $s1->id, null, 'employee_imports:'.$import->id);
    $page->dispatch('references-resolved', scope: 'employee_imports:'.$import->id, key: 'k');

    $grade = EmployeeImport::query()->where('parent_id', $import->id)->where('sheet', 'Grades')->where('status', '!=', 'discarded')->firstOrFail();
    expect($grade->rows()->first()->action)->toBe('create')->and((int) $import->refresh()->error_count)->toBe(0);
    app(WorkbookImports::class)->approve($import, $this->admin, 'decided');
    app(WorkbookImports::class)->start($import->refresh(), $this->admin);
    expect(Grade::query()->where('code', 'GX')->value('level_id'))->toBe($s1->id);
});
