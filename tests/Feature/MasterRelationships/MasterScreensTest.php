<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Organisation\Models\BusinessRegion;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\DimensionValue;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Models\ProfitCentre;
use App\Domain\Organisation\Models\Region;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Models\WorkMode;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Filament\Pages\EmploymentRequirementsPage;
use App\Filament\Pages\OrganisationDesigner;
use App\Filament\Pages\UnmappedMastersPage;
use App\Filament\RelationManagers\Masters\MasterCompaniesTab;
use App\Filament\RelationManagers\Masters\MasterCompanyValuesTab;
use App\Filament\RelationManagers\Masters\MasterLinksTab;
use App\Filament\RelationManagers\Masters\MasterTabs;
use App\Filament\RelationManagers\Masters\MasterUsageTab;
use App\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Filament\Resources\Designations\Pages\CreateDesignation;
use App\Filament\Resources\Designations\Pages\EditDesignation;
use App\Filament\Resources\Grades\Pages\EditGrade;
use App\Filament\Resources\Locations\Pages\EditLocation;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| Master relationships R4: masters are created with their essentials, links are optional and shown per master type
| (General · Companies · Links · Company values · Usage · History), organisation units can be placed later, and the
| Unmapped masters report lists what a business process would miss, within the person's access.
*/

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->t5 = Grade::query()->create(['name' => 'T5', 'code' => 'T5']);
    $this->sourcing = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC-MGR']);
});

function msTab(string $tab, $record, string $page)
{
    return Livewire::test($tab, ['ownerRecord' => $record, 'pageClass' => $page]);
}

it('shows each master the tabs its type has, with the form as General', function () {
    Livewire::test(EditDesignation::class, ['record' => $this->sourcing->getRouteKey()])
        ->assertSee('General')->assertSee('Companies')->assertSee('Links')->assertSee('Company values')->assertSee('Usage')->assertSee('History');

    // A grade has no company values; a department shows Links for its placement.
    Livewire::test(EditGrade::class, ['record' => $this->t5->getRouteKey()])->assertSee('Usage')->assertDontSee('Company values');
    expect(MasterCompanyValuesTab::canViewForRecord($this->t5, EditGrade::class))->toBeFalse()
        ->and(MasterLinksTab::canViewForRecord(Department::query()->create(['name' => 'Operations', 'code' => 'OPS']), EditDepartment::class))->toBeTrue()
        ->and(MasterUsageTab::canViewForRecord($this->mk, EditDesignation::class))->toBeTrue();
});

it('creates a designation with its name and code alone, and lists its links as optional', function () {
    Livewire::test(CreateDesignation::class)->fillForm(['name' => 'Category Buyer', 'code' => 'CAT-BUY'])->call('create')->assertHasNoFormErrors();
    $buyer = Designation::query()->where('code', 'CAT-BUY')->sole();

    expect($buyer->only(['level_id', 'grade_id', 'job_family_id', 'department_id']))->toBe(['level_id' => null, 'grade_id' => null, 'job_family_id' => null, 'department_id' => null]);
    msTab(MasterLinksTab::class, $buyer, EditDesignation::class)->assertSee('Not set (optional)')->assertSee('Usual department (information only)')->assertSee('Optional');
});

it('says on the Links tab what the employment data requirements need, and which companies have their own value', function () {
    $policy = Policy::query()->create(['type' => EmploymentRequirements::TYPE, 'name' => 'Hire rules', 'code' => 'EDR']);
    app(Policies::class)->draft($policy, ['required_on_hire' => ['grade_id']]);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::query()->create(['policy_type' => EmploymentRequirements::TYPE, 'policy_id' => $policy->id, 'name' => 'Applicability', 'priority' => 100, 'conditions' => []]);
    app(ConfigurationOverrides::class)->set($this->sourcing, $this->fe->id, 'grade_id', $this->t5->id, '2026-11-24', $this->admin, 'FynnEdge grade');

    msTab(MasterLinksTab::class, $this->sourcing, EditDesignation::class)
        ->assertSee('Required on hire by Hire rules (Warn): this designation has none to prefill')
        ->assertSee('FynnEdge Advisory: T5');
    msTab(MasterCompanyValuesTab::class, $this->sourcing, EditDesignation::class)->assertSee('FynnEdge Advisory')->assertSee('T5')->assertSee('In force');
    msTab(MasterCompaniesTab::class, $this->sourcing, EditDesignation::class)->assertSee('Every company of the organisation uses it.');
});

it('counts what uses a master on the Usage tab, and says it can be deleted when nothing does', function () {
    msTab(MasterUsageTab::class, $this->sourcing, EditDesignation::class)->assertSee('Nothing uses it.');
    app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2026-11-24'], ['company_id' => $this->mk->id, 'designation_id' => $this->sourcing->id]);

    msTab(MasterUsageTab::class, $this->sourcing, EditDesignation::class)->assertSee('employee position')->assertSee('Make it inactive instead');
});

it('places a new unit when it is created, or leaves it for later', function () {
    $node = OrganisationNode::query()->where('nodeable_type', Company::class)->where('nodeable_id', $this->mk->id)->first()
        ?? app(OrganisationTree::class)->attach($this->mk, null, 'Structure');

    Livewire::test(CreateDepartment::class)->fillForm(['name' => 'Sourcing', 'code' => 'SRC', 'organisation_placement' => (string) $node->id])->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateDepartment::class)->fillForm(['name' => 'Logistics', 'code' => 'LOG'])->call('create')->assertHasNoFormErrors();

    expect(Department::query()->where('code', 'SRC')->sole()->organisationNode()->value('parent_id'))->toBe($node->id)
        ->and(Department::query()->where('code', 'LOG')->sole()->organisationNode()->exists())->toBeFalse();
    msTab(MasterLinksTab::class, Department::query()->where('code', 'LOG')->sole(), EditDepartment::class)->assertSee('Not placed yet')->assertSee('Place it in the Organisation Designer');
});

it('lists unmapped masters with what follows, within the tenant, and only for people who may see the organisation', function () {
    $principal = Establishment::query()->where('company_id', $this->mk->id)->firstOrFail();
    Establishment::query()->create(['company_id' => $this->mk->id, 'legal_entity_id' => $principal->legal_entity_id, 'code' => 'MYS', 'name' => 'Mysuru', 'state' => 'KA', 'effective_from' => '2020-01-01']);
    Location::query()->create(['name' => 'Pune', 'code' => 'PUN', 'company_id' => $this->mk->id]);
    Department::query()->create(['name' => 'Logistics', 'code' => 'LOG']);
    $other = provisionTenant('Elsewhere');
    actAsTenant($other);
    Department::query()->create(['name' => 'Hidden', 'code' => 'HIDDEN']);
    actAsTenant($this->tenant);

    $page = Livewire::test(UnmappedMastersPage::class)
        ->assertSee('Sourcing Manager')->assertSee('Optional: nothing requires a grade on hire')
        ->assertSee('Logistics')->assertSee('Pune')->assertSee('take the head office')
        ->assertDontSee('HIDDEN');

    // With a grade required on Block, a designation without one becomes a blocker.
    $this->sourcing->update(['grade_id' => null]);
    $policy = Policy::query()->create(['type' => EmploymentRequirements::TYPE, 'name' => 'Hire rules', 'code' => 'EDR']);
    app(Policies::class)->draft($policy, ['required_on_hire' => ['grade_id']]);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::query()->create(['policy_type' => EmploymentRequirements::TYPE, 'policy_id' => $policy->id, 'name' => 'Applicability', 'priority' => 100, 'conditions' => []]);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block');
    Livewire::test(UnmappedMastersPage::class)->assertSee('Blocking')->assertSee('refused without a grade');

    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(UnmappedMastersPage::getUrl())->assertForbidden();
});

it('opens the Organisation Designer on a unit from a link', function () {
    expect(OrganisationDesigner::getUrl(['search' => 'LOG']))->toContain('search=LOG');
    Livewire::withQueryParams(['search' => 'LOG'])->test(OrganisationDesigner::class)->assertSet('search', 'LOG');
});

it('shows a location its establishment, and says when the head office applies', function () {
    $principal = Establishment::query()->where('company_id', $this->mk->id)->firstOrFail();
    Establishment::query()->create(['company_id' => $this->mk->id, 'legal_entity_id' => $principal->legal_entity_id, 'code' => 'MYS', 'name' => 'Mysuru', 'state' => 'KA', 'effective_from' => '2020-01-01']);
    $pune = Location::query()->create(['name' => 'Pune', 'code' => 'PUN', 'company_id' => $this->mk->id]);

    msTab(MasterLinksTab::class, $pune, EditLocation::class)->assertSee('Establishment')->assertSee('has more than one establishment');
    $pune->update(['establishment_id' => $principal->id]);
    msTab(MasterLinksTab::class, $pune->fresh(), EditLocation::class)->assertSee($principal->name);
});

// Every master with an edit page. Legal entities, establishments and skills are edited in a modal on their list page
// (no edit page, so no tabs); a legal entity and an establishment are parts of their company.
it('opens every master type with General, Usage and History, and the tabs that type has', function (string $class) {
    $record = match ($class) {
        Company::class => $this->mk,
        Location::class => Location::query()->create(['name' => 'Pune', 'code' => 'PUN', 'company_id' => $this->mk->id]),
        default => $class::query()->create(['name' => 'Sample master', 'code' => 'SAMPLE']),
    };
    $edit = Filament::getModelResource($class)::getPages()['edit']->getPage();

    $page = Livewire::test($edit, ['record' => $record->getRouteKey()])->assertOk()->assertSee('General')->assertSee('Usage')->assertSee('History');
    $tabs = array_values(array_intersect(MasterTabs::MANAGERS, $page->instance()->getRelationManagers()));

    // Usage on every master; Company values only where the type has company values (designations); every tab renders.
    expect($tabs)->toContain(MasterUsageTab::class)
        ->and(in_array(MasterCompanyValuesTab::class, $tabs, true))->toBe($class === Designation::class);
    foreach ($tabs as $tab) {
        msTab($tab, $record, $edit)->assertOk();
    }
})->with(fn () => collect([
    BusinessUnit::class, Division::class, Designation::class,
    Region::class, Department::class, EmploymentType::class, Location::class,
    JobFamily::class, EmployeeCategory::class, Team::class,
    ProfitCentre::class, DimensionValue::class, CostCentre::class,
    WorkMode::class, Company::class, BusinessRegion::class, Grade::class,
    Level::class,
])->mapWithKeys(fn ($class) => [class_basename($class) => [$class]])->all());

it('says plainly when there is nothing to show: no requirements policy, and nothing unmapped', function () {
    $this->sourcing->update(['grade_id' => $this->t5->id]);

    Livewire::test(EmploymentRequirementsPage::class)->assertOk()->assertSee('No employment data requirements yet.');
    Livewire::test(UnmappedMastersPage::class)->assertOk()
        ->assertSee('Every designation has a grade.')->assertSee('Every such location has its establishment.');
});
