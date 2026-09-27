<?php

use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Filament\Pages\OrganisationDesigner;
use App\Filament\Resources\BusinessUnits\BusinessUnitResource;
use App\Filament\Resources\CostCentres\CostCentreResource;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\Designations\DesignationResource;
use App\Filament\Resources\Divisions\DivisionResource;
use App\Filament\Resources\EmployeeCategories\EmployeeCategoryResource;
use App\Filament\Resources\EmploymentTypes\EmploymentTypeResource;
use App\Filament\Resources\Grades\GradeResource;
use App\Filament\Resources\JobFamilies\JobFamilyResource;
use App\Filament\Resources\Levels\LevelResource;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\ProfitCentres\ProfitCentreResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\WorkModes\WorkModeResource;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create(['name' => 'Acme Group', 'code' => 'ACME']);
    $this->root = app(OrganisationTree::class)->attach($this->company);
    $this->admin = tenantUser($this->tenant, ['*']);
    actAsTenant(null);
    $this->actingAs($this->admin);
});

it('renders index and create pages for every organisation and people-setup resource', function () {
    $resources = [
        LocationResource::class,
        BusinessUnitResource::class,
        DivisionResource::class,
        DepartmentResource::class,
        TeamResource::class,
        CostCentreResource::class,
        ProfitCentreResource::class,
        LevelResource::class,
        GradeResource::class,
        JobFamilyResource::class,
        DesignationResource::class,
        EmploymentTypeResource::class,
        EmployeeCategoryResource::class,
        WorkModeResource::class,
    ];

    foreach ($resources as $resource) {
        $this->get($resource::getUrl('index'))->assertOk();
        $this->get($resource::getUrl('create'))->assertOk();
    }

    $level = app(TenantContext::class)->runAs($this->tenant, fn () => Level::query()->first());
    $this->get(LevelResource::getUrl('edit', ['record' => $level]))->assertOk()->assertSee('History');
});

it('renders the organisation designer with the tree and chart', function () {
    $this->get(OrganisationDesigner::getUrl())->assertOk()->assertSee('Acme Group')->assertSee('Add root unit');
});

it('hides the designer from users without the design permission', function () {
    $this->actingAs(tenantUser($this->tenant, ['organisation.view']));

    $this->get(OrganisationDesigner::getUrl())->assertForbidden();
});

it('adds, renames and moves units through designer actions with audit reasons', function () {
    actAsTenant($this->tenant);

    Livewire::test(OrganisationDesigner::class)
        ->callAction('addChild', data: ['type' => 'business_unit', 'mode' => 'new', 'name' => 'Technology', 'code' => 'TECH', 'audit_reason' => 'New BU'], arguments: ['node' => $this->root->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Unit added');

    $bu = OrganisationNode::query()->where('nodeable_type', BusinessUnit::class)->first();
    expect($bu->parent_id)->toBe($this->root->id)
        ->and($bu->nodeable->company_id)->toBe($this->company->id)
        ->and($bu->nodeable->auditEvents()->value('reason'))->toBe('New BU');

    Livewire::test(OrganisationDesigner::class)
        ->callAction('rename', data: ['name' => 'Tech & Product', 'audit_reason' => 'Rebrand'], arguments: ['node' => $bu->id])
        ->assertHasNoActionErrors();
    expect($bu->nodeable->refresh()->name)->toBe('Tech & Product');

    Livewire::test(OrganisationDesigner::class)
        ->callAction('addRoot', data: ['type' => 'company', 'mode' => 'existing', 'unit_id' => Company::factory()->create(['name' => 'Acme Services'])->id])
        ->assertHasNoActionErrors();

    $second = OrganisationNode::query()->whereNull('parent_id')->where('id', '!=', $this->root->id)->first();

    Livewire::test(OrganisationDesigner::class)
        ->callAction('move', data: ['parent_id' => $second->id, 'audit_reason' => 'Transfer'], arguments: ['node' => $bu->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Moved');
    expect($bu->refresh()->parent_id)->toBe($second->id);

    // The parent selector only offers legal targets, so an illegal one is rejected as validation.
    Livewire::test(OrganisationDesigner::class)
        ->callAction('move', data: ['parent_id' => $bu->id], arguments: ['node' => $second->id])
        ->assertHasActionErrors(['parent_id']);
    expect($second->refresh()->parent_id)->toBeNull();

    Livewire::test(OrganisationDesigner::class)
        ->set('search', 'tech')
        ->assertSee('Tech & Product')
        ->assertDontSee('Acme Group');
});
