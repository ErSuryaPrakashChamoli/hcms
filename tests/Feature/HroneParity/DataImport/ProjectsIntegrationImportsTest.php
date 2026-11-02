<?php

use App\Domain\Integration\Models\JvCode;
use App\Domain\Integration\Services\IntegrationSystems;
use App\Domain\Integration\Services\JvCodes;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Customer;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMember;
use App\Domain\Projects\Services\Projects;
use App\Filament\Resources\JvCodes\Pages\ManageJvCodes;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\RelationManagers\MembersRelationManager;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/Di1Helpers.php';

/* Data import DI-1 — Core → Customers, Project Management → Projects, Integration → JV mappings. */

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->acme = di1Company();
    $this->asha = di1Hire($this->acme, 'EMP1');
    $this->ravi = di1Hire($this->acme, 'EMP2', 'Ravi');
    $this->meera = di1Hire($this->acme, 'EMP3', 'Meera');
});

it('loads customers by code with contact, country and tax id, and checks the cells', function () {
    Customer::query()->create(['code' => 'GLOBEX', 'name' => 'Globex']);
    $csv = "Code,Name,Contact name,E-mail,Phone,Address,Country code,Tax id,Status\n"
        ."GLOBEX,Globex Corporation,Priya Nair,Priya.Nair@Globex.example,,,IN,29abcde1234f1z5,\n"
        ."INITECH,Initech,Tom,tom@initech.example,+44 20 7946 0000,1 Silicon Way,GB,GB123456789,active\n"
        ."BAD,Bad,,not-an-email,,,XX,,\n";
    $import = di1Stage('customers', $csv);
    $rows = di1Rows($import);
    expect($rows[1]['action'])->toBe('update')->and($rows[2]['action'])->toBe('create')
        ->and(implode(' ', $rows[3]['errors']))->toContain("E-mail 'not-an-email' is not an e-mail address.")->toContain("Country code 'XX' is not a country");
    di1Run($import);
    $globex = Customer::query()->where('code', 'GLOBEX')->first();
    expect($globex->name)->toBe('Globex Corporation')->and($globex->email)->toBe('priya.nair@globex.example')->and($globex->tax_id)->toBe('29ABCDE1234F1Z5')
        ->and(Customer::query()->where('code', 'INITECH')->first()->country_code)->toBe('GB');

    $newOnly = di1Stage('customers', "Code,Name\nGLOBEX,Globex 2\nUMBRELLA,Umbrella\n", 'new');
    expect(di1Rows($newOnly)[1]['action'])->toBe('skip')->and($newOnly->create_count)->toBe(1);
});

it('loads projects with customer, company, dates, manager, billable flag, status and members', function () {
    Customer::query()->create(['code' => 'GLOBEX', 'name' => 'Globex']);
    $csv = "Code,Name,Customer code,Company code,Start date,End date,Manager code,Billable,Status,Description,Member codes\n"
        ."GLX,Globex portal,GLOBEX,ACME,2026-04-01,2027-03-31,EMP1,yes,On hold,Portal,EMP2; emp3\n"
        ."INT,Internal tools,,ACME,2026-06-01,,,,,,\n"
        ."BAD,Bad,NOPE,,2026-05-01,2026-04-01,EMP9,perhaps,stalled,,EMP2;EMP99\n";
    $import = di1Stage('projects', $csv);
    $rows = di1Rows($import);
    expect($import->create_count)->toBe(2)
        ->and($rows[3]['errors'])->toContain("Customer code 'NOPE' does not exist.")->toContain('A project ends on or after the day it starts.')
        ->toContain("Manager code 'EMP9' is not an employee you can reach.")->toContain('Billable must be yes or no.')
        ->toContain("Member code 'EMP99' is not an employee you can reach.")
        ->and(implode(' ', $rows[3]['errors']))->toContain("Status 'stalled' is not one of");
    di1Run($import);
    $glx = Project::query()->where('code', 'GLX')->first();
    expect($glx->customer->code)->toBe('GLOBEX')->and($glx->company_id)->toBe($this->acme->id)->and($glx->start_date->toDateString())->toBe('2026-04-01')
        ->and($glx->manager_employee_id)->toBe($this->asha->id)->and($glx->is_billable)->toBeTrue()->and($glx->status)->toBe(ProjectStatus::OnHold)
        ->and($glx->members()->pluck('employee_id')->sort()->values()->all())->toBe([$this->ravi->id, $this->meera->id])
        ->and(di1Rows($import)[1]['result'])->toContain('added 2 member(s): EMP2, EMP3')
        ->and(Project::query()->where('code', 'INT')->first()->status)->toBe(ProjectStatus::Planned);

    // Same values plus a new member: an update that only adds the member; nobody is taken off.
    $again = di1Stage('projects', "Code,Name,Member codes\nGLX,Globex portal,EMP1;EMP2\nINT,Internal tools,\n");
    $rows = di1Rows($again);
    expect($rows[1]['action'])->toBe('update')->and($rows[1]['match']['changes'])->toBe(['members'])->and($rows[2]['action'])->toBe('skip');
    di1Run($again);
    expect($glx->members()->count())->toBe(3)->and(di1Rows($again)[1]['result'])->toContain('added 1 member(s): EMP1');

    // Update only: a new code is skipped.
    $update = di1Stage('projects', "Code,Name,Status\nNEWP,New,active\nINT,Internal tools,completed\n", 'update');
    expect(di1Rows($update)[1]['action'])->toBe('skip')->and(di1Rows($update)[2]['action'])->toBe('update');
});

it('keeps project members through the Projects service on the project screen too', function () {
    $project = Project::query()->create(['code' => 'GLX', 'name' => 'Globex portal']);
    app(Projects::class)->addMember($project, $this->asha);
    expect(fn () => app(Projects::class)->addMember($project, $this->asha))->toThrow(RuntimeException::class, 'EMP1 is already on project GLX.');

    Livewire::test(ListProjects::class)->assertOk()->assertSee('Globex portal');
    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
        ->callTableAction('create', data: ['employee_id' => $this->ravi->id, 'role' => 'Developer', 'allocation_percent' => 50])
        ->assertHasNoTableActionErrors();
    $member = ProjectMember::query()->where('employee_id', $this->ravi->id)->first();
    expect($member->role)->toBe('Developer')->and((float) $member->allocation_percent)->toBe(50.0);

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
        ->callTableAction('create', data: ['employee_id' => $this->asha->id]);
    expect(ProjectMember::query()->where('employee_id', $this->asha->id)->count())->toBe(1);

    app(Projects::class)->endMember($member, '2026-09-30');
    $this->travelTo('2026-10-02 10:00:00');
    expect(app(Projects::class)->isMember($project, $this->ravi->id))->toBeFalse();
    app(Projects::class)->addMember($project, $this->ravi, ['from_date' => '2026-10-02']);
    expect(ProjectMember::query()->where('employee_id', $this->ravi->id)->count())->toBe(2);
});

it('sets JV codes per dimension, shared across records and per accounting system, through JvCodes', function () {
    $eng = Department::factory()->create(['company_id' => $this->acme->id, 'code' => 'ENG', 'name' => 'Engineering']);
    $fin = Department::factory()->create(['company_id' => $this->acme->id, 'code' => 'FIN', 'name' => 'Finance']);
    $qa = Department::factory()->create(['company_id' => $this->acme->id, 'code' => 'QA', 'name' => 'Quality', 'parent_department_id' => $eng->id]);
    $erp = app(IntegrationSystems::class)->create(['code' => 'tally', 'name' => 'Tally'], $this->hr)['system'];
    $csv = "Dimension,Code,JV code,Accounting system code\n"
        ."department,ENG,4100,\n"
        ."Department,fin,4100,\n"
        ."department,ENG,4100-T,TALLY\n"
        ."department,QA,4200,\n"
        ."sub department,QA,4200,\n"
        ."employee,EMP1,7000-EMP1,\n"
        ."castle,X,1,\n"
        ."department,ENG,9999,\n"
        ."grade,NOPE,1,\n"
        ."department,FIN,1,SAP\n";
    $import = di1Stage('jv_mappings', $csv);
    $rows = di1Rows($import);
    expect($import->create_count)->toBe(5)
        ->and($rows[4]['errors'])->toBe(["'QA': It is a sub department: use the JV sub department mapping."])
        ->and($rows[7]['errors'][0])->toContain("Dimension 'castle' is not one of")
        ->and($rows[8]['errors'])->toBe(['The same record (and system) is in row 1.'])
        ->and($rows[9]['errors'])->toBe(["Grade code 'NOPE' does not exist."])
        ->and($rows[10]['errors'])->toBe(["Accounting system code 'SAP' is not an integration system (Integrations → Systems)."]);
    di1Run($import);

    $jv = app(JvCodes::class);
    expect($jv->codeFor('department', $eng))->toBe('4100')->and($jv->codeFor('department', $fin))->toBe('4100')
        ->and($jv->codeFor('department', $eng, $erp))->toBe('4100-T')->and($jv->codeFor('department', $fin, $erp))->toBe('4100')
        ->and($jv->codeFor('sub_department', $qa))->toBe('4200')->and($jv->codeFor('employee', $this->asha))->toBe('7000-EMP1')
        ->and(JvCode::query()->count())->toBe(5);

    // A changed code updates, the same code is skipped; "new only" leaves codes on file.
    $again = di1Stage('jv_mappings', "Dimension,Code,JV code\ndepartment,ENG,4101\ndepartment,FIN,4100\n");
    expect(di1Rows($again)[1]['action'])->toBe('update')->and(di1Rows($again)[2]['action'])->toBe('skip');
    di1Run($again);
    expect($jv->codeFor('department', $eng))->toBe('4101')->and(di1Rows($again)[1]['result'])->toBe('Changed the JV code of Department ENG from 4100 to 4101');
    $newOnly = di1Stage('jv_mappings', "Dimension,Code,JV code\ndepartment,ENG,4102\n", 'new');
    expect(di1Rows($newOnly)[1]['action'])->toBe('skip');
});

it('reads the dimension from the JV process for a file without a Dimension column, and refuses a sub branch as a branch', function () {
    $blr = Location::factory()->create(['company_id' => $this->acme->id, 'code' => 'BLR', 'name' => 'Bengaluru']);
    Location::factory()->create(['company_id' => $this->acme->id, 'code' => 'BLR-WF', 'name' => 'Whitefield', 'parent_location_id' => $blr->id]);
    $import = di1Stage('jv_mappings', "Code,JV code\nBLR,2000\nBLR-WF,2000\n", 'both', ['dimension' => 'location']);
    expect(di1Rows($import)[1]['action'])->toBe('create')
        ->and(di1Rows($import)[2]['errors'])->toBe(["'BLR-WF': It is a sub branch: use the JV sub branch mapping."]);
    di1Run($import);
    expect(app(JvCodes::class)->codeFor('location', $blr))->toBe('2000');

    // The JV codes screen sets codes the same way.
    Livewire::test(ManageJvCodes::class)
        ->callAction('create', data: ['dimension' => 'sub_branch', 'record_code' => 'BLR-WF', 'code' => '2100'])
        ->assertHasNoActionErrors();
    expect(JvCode::query()->where('dimension', 'sub_branch')->value('code'))->toBe('2100');
    Livewire::test(ManageJvCodes::class)->callAction('create', data: ['dimension' => 'sub_branch', 'record_code' => 'BLR', 'code' => '2100']);
    expect(JvCode::query()->where('dimension', 'sub_branch')->count())->toBe(1);
    Livewire::test(ManageJvCodes::class)->assertSee('BLR-WF — Whitefield')->assertSee('2100');
});

it('lets project.view see customers and projects but only project.manage change them', function () {
    $viewer = tenantUser($this->tenant, ['project.view']);
    $manager = tenantUser($this->tenant, ['project.manage']);
    $project = Project::query()->create(['code' => 'GLX', 'name' => 'Globex portal']);
    expect($viewer->can('viewAny', Project::class))->toBeTrue()->and($viewer->can('update', $project))->toBeFalse()
        ->and($viewer->can('create', Customer::class))->toBeFalse()
        ->and($manager->can('update', $project))->toBeTrue()->and($manager->can('create', Customer::class))->toBeTrue()
        ->and(tenantUser($this->tenant, ['employee.view'])->can('viewAny', Project::class))->toBeFalse()
        ->and(collect(config('peopleos.roles.tenant-hr-admin.permissions'))->contains('project.*'))->toBeTrue();
});
