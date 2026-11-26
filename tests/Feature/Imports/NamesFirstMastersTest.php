<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Integration\Services\JvCodes;
use App\Domain\Organisation\Enums\GeoPlaceKind;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\GeoPlace;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Services\DesignationDefaults;
use App\Domain\Partners\Models\Vendor;
use App\Domain\Partners\Services\Vendors;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Projects\Models\Customer;
use App\Domain\Projects\Models\Project;
use Illuminate\Support\Facades\Storage;

/*
| Names first (2026-11-26) in the organisation and other master uploads: places, branches, designations' company values,
| JV mappings, projects, vendors, designation-wise skills and the unit tree, all by name (NamesFirstTest covers the
| shared rules).
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
    $this->asha = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP1', 'work_email' => 'asha@mk.test'], ['company_id' => $this->mk->id]);
    $this->ravi = app(HireEmployeeAction::class)->handle(['first_name' => 'Ravi', 'last_name' => 'Kumar'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP2'], ['company_id' => $this->mk->id]);
});

function nfmUpload(string $type, string $csv, $user, string $mode = 'both', array $options = []): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid($type).'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = ImportTypes::register($type, $path, "{$type}.csv", $user, ['mode' => $mode] + $options);
    $service = ImportTypes::service($type);
    $service->inspect($import);
    $import->refresh();
    $service->map($import, $import->mapping);

    return $service->validate($import->refresh());
}

function nfmRun(EmployeeImport $import, $user): EmployeeImport
{
    $service = ImportTypes::service($import->type);
    $service->approve($import->refresh(), $user, 'test');

    return $service->run($import->refresh(), $user);
}

function nfmRows(EmployeeImport $import): array
{
    return $import->rows()->orderBy('row_number')->get()->keyBy('row_number')->map(fn ($r) => $r->only(['action', 'status', 'errors', 'match', 'result']))->all();
}

it('names states and districts in the geo uploads', function () {
    $run = nfmRun(nfmUpload('geo_states', "Code,Name,Country\nKA,Karnataka,India\n", $this->admin), $this->admin);
    expect($run->failure_count)->toBe(0);
    $districts = nfmUpload('geo_districts', "Code,Name,State\nBLR-U,Bengaluru Urban,karnataka\nMYS,Mysuru,Nowhere\n", $this->admin);
    expect(nfmRows($districts)[1]['action'])->toBe('create')->and(nfmRows($districts)[2]['errors'][0])->toContain("State 'Nowhere' does not exist");
    nfmRun($districts, $this->admin);
    $cities = nfmUpload('geo_cities', "Code,Name,State,District\nBLR,Bengaluru,Karnataka,Bengaluru Urban\nNOC,No code,Karnataka,\n,Blank,Karnataka,\n", $this->admin);
    $rows = nfmRows($cities);
    expect($rows[1]['action'])->toBe('create')->and($rows[3]['errors'][0])->toContain('Code is required');
    nfmRun($cities, $this->admin);
    expect(GeoPlace::query()->where('kind', GeoPlaceKind::City->value)->where('code', 'BLR')->first()->district->code)->toBe('BLR-U')
        ->and(GeoPlace::query()->where('kind', 'state')->where('code', 'KA')->value('country_code'))->toBe('IN');
});

it('adds branches and sub branches by name, with state and country names', function () {
    $import = nfmUpload('locations', "Name,Company,State,Country\nBengaluru office,Markedge Technologies,Karnataka,India\n", $this->admin);
    expect(nfmRows($import)[1]['action'])->toBe('create');
    nfmRun($import, $this->admin);
    $blr = Location::query()->where('name', 'Bengaluru office')->firstOrFail();
    expect($blr->code)->toBe('BENGALURU_OFFICE')->and($blr->state_code)->toBe('KA')->and($blr->country_code)->toBe('IN')->and($blr->company_id)->toBe($this->mk->id);

    $subs = nfmUpload('sub_branches', "Name,Parent branch\nWhitefield,Bengaluru office\nWhitefield annexe,Whitefield\nBengaluru office,Bengaluru office\n", $this->admin);
    $rows = nfmRows($subs);
    expect($rows[1]['action'])->toBe('create')->and($rows[2]['action'])->toBe('create')->and($rows[3]['errors'])->toContain('A sub branch cannot sit inside itself.');
    nfmRun($subs, $this->admin);
    expect(Location::query()->where('name', 'Whitefield annexe')->first()->parentLocation->name)->toBe('Whitefield');
});

it('gives a company its own designation values on a row naming the designation', function () {
    $l1 = Level::query()->create(['code' => 'LX1', 'name' => 'Junior', 'status' => 'active']);
    $import = nfmUpload('designations', "Code,Name,Level,Company\n,Buyer,,\n,Buyer,Junior,FynnEdge Advisory\n,Buyer,Junior,Markedge Technologies\n,Ghost,Junior,FE\n", $this->admin);
    $rows = nfmRows($import);
    expect($rows[1]['action'])->toBe('create')->and($rows[2]['action'])->toBe('update')->and($rows[3]['action'])->toBe('update')
        ->and($rows[4]['errors'])->toBe(["Designation 'Ghost' is not on file: add it with a row without Company first (or earlier in this file)."]);
    nfmRun($import, $this->admin);
    $buyer = Designation::query()->where('name', 'Buyer')->firstOrFail();
    expect($buyer->code)->toBe('BUYER')->and(app(DesignationDefaults::class)->for($buyer, $this->fe->id)['level_id'])->toBe($l1->id)
        ->and(nfmRows($import)[2]['result'])->toContain('values of Buyer');
});

it('maps JV codes by record name, per dimension', function () {
    $eng = Department::factory()->create(['company_id' => $this->mk->id, 'code' => 'ENG', 'name' => 'Engineering']);
    $import = nfmUpload('jv_mappings', "Dimension,Record,JV code\ndepartment,Engineering,4100\nemployee,Asha Rao,7000-1\nemployee,Ravi Kumar,7000-2\ndepartment,Nowhere,1\n", $this->admin);
    $rows = nfmRows($import);
    expect($rows[1]['action'])->toBe('create')->and($rows[2]['action'])->toBe('create')->and($rows[3]['action'])->toBe('create')
        ->and($rows[4]['errors'])->toBe(["Department 'Nowhere' does not exist."]);
    nfmRun($import, $this->admin);
    expect(app(JvCodes::class)->codeFor('department', $eng))->toBe('4100')->and(app(JvCodes::class)->codeFor('employee', $this->ravi))->toBe('7000-2');
});

it('loads projects with customer, company, manager and members by name', function () {
    Customer::query()->create(['code' => 'GLOBEX', 'name' => 'Globex Corporation']);
    $import = nfmUpload('projects', "Name,Customer,Company,Manager,Members\nPortal,Globex Corporation,Markedge Technologies,Asha Rao,Ravi Kumar; EMP1\n", $this->admin);
    expect(nfmRows($import)[1]['action'])->toBe('create', json_encode(nfmRows($import)));
    nfmRun($import, $this->admin);
    $p = Project::query()->where('name', 'Portal')->firstOrFail();
    expect($p->code)->toBe('PORTAL')->and($p->manager_employee_id)->toBe($this->asha->id)->and($p->customer->code)->toBe('GLOBEX')
        ->and($p->members()->pluck('employee_id')->sort()->values()->all())->toBe([$this->asha->id, $this->ravi->id]);
});

it('adds and changes vendors by name with state and country names', function () {
    $import = nfmUpload('vendors', "Vendor name,Kind,State,Country\nSkyline Staffing,".array_key_first(app(Vendors::class)->kinds()).",Karnataka,India\nSkyline Staffing,,,\n", $this->admin);
    $rows = nfmRows($import);
    expect($rows[1]['action'])->toBe('create')->and($rows[2]['errors'][0])->toContain('row 1');
    nfmRun($import, $this->admin);
    $v = Vendor::query()->where('name', 'Skyline Staffing')->firstOrFail();
    expect($v->code)->toBe('SKYLINE_STAFFING')->and($v->state)->toBe('KA')->and($v->country)->toBe('IN');
    $again = nfmUpload('vendors', "Vendor name,Contact name\nSkyline Staffing,Neha\n", $this->admin);
    expect(nfmRows($again)[1]['action'])->toBe('update');
    nfmRun($again, $this->admin);
    expect($v->fresh()->contact_name)->toBe('Neha')->and(Vendor::query()->count())->toBe(1);
});

it('reads designation wise skills by name', function () {
    $import = nfmUpload('skills', "Name\nPython\n", $this->admin);
    nfmRun($import, $this->admin);
    Designation::factory()->create(['code' => 'SE', 'name' => 'Software engineer']);
    $ds = nfmUpload('designation_skills', "Designation,Skill,Expected level\nSoftware engineer,Python,3\n", $this->admin);
    expect(nfmRows($ds)[1]['action'])->toBe('create');
    $done = nfmRun($ds, $this->admin);
    expect($done->failure_count)->toBe(0)->and(nfmRows($ds)[1]['result'])->toContain('of Software engineer');
});

it('places units in the tree by name and sets applicability by company names', function () {
    nfmRun(nfmUpload('divisions', "Name,Company,Applies to\nRetail,Markedge Technologies,Markedge Technologies\n", $this->admin), $this->admin);
    $tree = nfmUpload('organisation_tree', "Unit type,Unit,Parent type,Parent\ncompany,Markedge Technologies,,\ndivision,Retail,company,Markedge Technologies\n", $this->admin);
    expect(collect(nfmRows($tree))->pluck('errors')->filter()->all())->toBe([]);
    $done = nfmRun($tree, $this->admin);
    expect($done->failure_count)->toBe(0);
});
