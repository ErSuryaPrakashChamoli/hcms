<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\ProfitCentre;
use App\Domain\Organisation\Models\Team;
use App\Filament\Resources\BusinessUnits\Pages\CreateBusinessUnit;
use App\Filament\Resources\BusinessUnits\Pages\EditBusinessUnit;
use App\Filament\Resources\CostCentres\Pages\CreateCostCentre;
use App\Filament\Resources\CostCentres\Pages\EditCostCentre;
use App\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Filament\Resources\Departments\Pages\ListDepartments;
use App\Filament\Resources\Divisions\Pages\CreateDivision;
use App\Filament\Resources\Divisions\Pages\EditDivision;
use App\Filament\Resources\LetterSignatories\Pages\ManageLetterSignatories;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\ProfitCentres\Pages\CreateProfitCentre;
use App\Filament\Resources\ProfitCentres\Pages\EditProfitCentre;
use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use Filament\Forms\Components\Hidden;
use Livewire\Livewire;

/*
| Owner, 2026-11-26: a company-owned master (department, location, team, …) is not asked for its company on the Details
| step any more: "Applies to" is the one place companies are chosen, and the record's own company follows it. A change of
| where it applies brings along what it uses (its parent department, cost centre, …) instead of refusing.
*/

beforeEach(function () {
    $this->travelTo('2026-11-26 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->applicability = app(EntityApplicability::class);
    $this->mk = Company::factory()->create(['name' => 'MarkEdge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
});

it('asks for the company nowhere but "Applies to" on every company-owned master', function () {
    $department = Department::factory()->create(['company_id' => $this->mk->id]);
    $location = Location::factory()->create(['company_id' => $this->mk->id]);

    // Creating: the company is a hidden value worked out from "Applies to", never a field to fill in.
    foreach ([CreateDepartment::class, CreateLocation::class, CreateTeam::class, CreateDivision::class, CreateBusinessUnit::class, CreateCostCentre::class, CreateProfitCentre::class] as $page) {
        Livewire::test($page)->assertOk()->assertFormFieldExists('company_id', fn ($field) => $field instanceof Hidden);
    }
    // Editing: no company field at all; "Applies to…" changes where it applies.
    Livewire::test(EditDepartment::class, ['record' => $department->getRouteKey()])->assertOk()->assertFormFieldDoesNotExist('company_id')->assertSee('Applies to…');
    Livewire::test(EditLocation::class, ['record' => $location->getRouteKey()])->assertOk()->assertFormFieldDoesNotExist('company_id');
    foreach ([EditTeam::class => Team::class, EditDivision::class => Division::class,
        EditBusinessUnit::class => BusinessUnit::class, EditCostCentre::class => CostCentre::class,
        EditProfitCentre::class => ProfitCentre::class] as $page => $model) {
        $record = $model::factory()->create(['company_id' => $this->mk->id]);
        Livewire::test($page, ['record' => $record->getRouteKey()])->assertOk()->assertFormFieldDoesNotExist('company_id');
    }
    Livewire::test(ListDepartments::class)->assertOk()->assertTableColumnDoesNotExist('company.name')->assertTableColumnExists('cfg_applies_to');
});

it('gives a new department the company chosen in "Applies to"', function () {
    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Sales', 'code' => 'SAL', 'entity_applicability' => ['mode' => 'single', 'company' => $this->fe->id]])
        ->call('create')->assertHasNoFormErrors();
    $sales = Department::query()->where('code', 'SAL')->sole();
    expect($sales->company_id)->toBe($this->fe->id)->and($this->applicability->reach($sales))->toBe([$this->fe->id]);

    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Group Finance', 'code' => 'GFIN', 'entity_applicability' => ['mode' => 'selected', 'companies' => [$this->fe->id, $this->mk->id]]])
        ->call('create')->assertHasNoFormErrors();
    $finance = Department::query()->where('code', 'GFIN')->sole();
    expect($finance->company_id)->toBe(min($this->mk->id, $this->fe->id))->and($this->applicability->reach($finance))->toBe([$this->mk->id, $this->fe->id]);

    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Legal', 'code' => 'LEG', 'entity_applicability' => ['mode' => 'all', 'include_future' => true]])
        ->call('create')->assertHasNoFormErrors();
    expect(Department::query()->where('code', 'LEG')->sole()->company_id)->toBeNull();

    // "Current entity only" needs the company.
    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Ops', 'code' => 'OPS', 'entity_applicability' => ['mode' => 'single', 'company' => null]])
        ->call('create')->assertHasFormErrors(['entity_applicability.company']);
});

it('brings the parent department along when a department is created for more companies than its parent', function () {
    $marketing = Department::factory()->create(['name' => 'Marketing', 'code' => 'MKT', 'company_id' => $this->mk->id]);

    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Content & Creative', 'code' => 'CONTENT', 'parent_department_id' => $marketing->id,
            'entity_applicability' => ['mode' => 'selected', 'companies' => [$this->mk->id, $this->fe->id]]])
        ->assertSee('Department "Marketing (MKT)" will also apply to FynnEdge Advisory, because this department uses it.')
        ->call('create')->assertHasNoFormErrors()
        ->assertNotified('Also applied what it uses');

    $content = Department::query()->where('code', 'CONTENT')->sole();
    expect($this->applicability->reach($content))->toBe([$this->mk->id, $this->fe->id])
        ->and($this->applicability->reach($marketing))->toBe([$this->mk->id, $this->fe->id])
        ->and($marketing->refresh()->company_id)->toBe($this->mk->id)
        ->and(AuditEvent::query()->where('action', AuditAction::ApplicabilityChanged->value)->where('entity_type', Department::class)->where('entity_id', $marketing->id)->value('reason'))
        ->toStartWith('Brought along with department "Content & Creative (CONTENT)"');
});

it('changes where an existing department applies and brings its parent along, instead of refusing (the 2026-11-26 report)', function () {
    $marketing = Department::factory()->create(['name' => 'Marketing', 'code' => 'MKT', 'company_id' => $this->mk->id]);
    $content = Department::factory()->create(['name' => 'Content & Creative', 'code' => 'CONTENT', 'company_id' => $this->mk->id, 'parent_department_id' => $marketing->id]);

    // Left as it is, the parent still blocks the change, and says how to fix it.
    $kept = $this->applicability->plan($content, ApplicabilitySelection::all(false), $this->admin);
    expect($kept->blockers)->toBe(['It uses the Department "Marketing (MKT)", which FynnEdge Advisory cannot use. Make that department apply there too, or choose other companies.'])
        ->and($kept->canBringAlong())->toBeTrue();

    Livewire::test(ListDepartments::class)
        ->callTableAction('manageApplicability', $content, ['mode' => 'all', 'include_future' => false, 'effective_from' => '2026-11-26', 'confirmed' => true, 'audit_reason' => 'Shared content team'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Applies to MarkEdge Technologies, FynnEdge Advisory');

    expect($this->applicability->reach($content))->toBe([$this->mk->id, $this->fe->id])
        ->and($this->applicability->reach($marketing))->toBe([$this->mk->id, $this->fe->id]);

    // Unticking "Also apply what it uses" keeps the parent as it is, and the change waits.
    $other = Department::factory()->create(['name' => 'Brand', 'code' => 'BRD', 'company_id' => $this->mk->id, 'parent_department_id' => Department::factory()->create(['code' => 'PR', 'company_id' => $this->mk->id])->id]);
    Livewire::test(ListDepartments::class)
        ->callTableAction('manageApplicability', $other, ['mode' => 'all', 'include_future' => false, 'effective_from' => '2026-11-26', 'with_dependencies' => false, 'confirmed' => true, 'audit_reason' => 'x'])
        ->assertNotified('Not changed');
    expect($this->applicability->reach($other))->toBe([$this->mk->id]);
});

it('moves a department to the company chosen when it stops applying to its own, unless people there still use it', function () {
    $ops = Department::factory()->create(['name' => 'Operations', 'code' => 'OPS', 'company_id' => $this->mk->id]);

    Livewire::test(ListDepartments::class)
        ->callTableAction('manageApplicability', $ops, ['mode' => 'single', 'company' => $this->fe->id, 'effective_from' => '2026-11-26', 'audit_reason' => 'FynnEdge runs it'])
        ->assertHasNoTableActionErrors();

    expect($ops->refresh()->company_id)->toBe($this->fe->id)->and($this->applicability->reach($ops))->toBe([$this->fe->id]);
});

it('takes a new location\'s company from its establishment, and keeps such a location to that company', function () {
    $establishment = Establishment::query()->where('company_id', $this->fe->id)->firstOrFail();

    Livewire::test(CreateLocation::class)
        ->fillForm(['name' => 'Gurugram', 'code' => 'GGN', 'country_code' => 'IN', 'establishment_id' => $establishment->id])
        // Picking the establishment sets "Applies to" to its company.
        ->assertFormSet(['entity_applicability.mode' => 'single', 'entity_applicability.company' => $this->fe->id])
        ->call('create')->assertHasNoFormErrors();
    expect(Location::query()->where('code', 'GGN')->sole()->company_id)->toBe($this->fe->id);

    Livewire::test(CreateLocation::class)
        ->fillForm(['name' => 'Noida', 'code' => 'NOI', 'country_code' => 'IN', 'establishment_id' => $establishment->id,
            'entity_applicability' => ['mode' => 'selected', 'companies' => [$this->mk->id, $this->fe->id]]])
        ->call('create')->assertHasFormErrors(['entity_applicability.mode']);
    expect(Location::query()->where('code', 'NOI')->exists())->toBeFalse();

    // Without an establishment or parent, a location shared with every entity still needs a company: the first one.
    Livewire::test(CreateLocation::class)
        ->fillForm(['name' => 'Remote', 'code' => 'RMT', 'country_code' => 'IN', 'entity_applicability' => ['mode' => 'all', 'include_future' => true]])
        ->call('create')->assertHasNoFormErrors();
    $remote = Location::query()->where('code', 'RMT')->sole();
    expect($remote->company_id)->not->toBeNull()->and($this->applicability->reach($remote))->toBe($this->applicability->allCompanyIds());
});

it('creates a signing authority for the companies chosen in "Applies to"', function () {
    Livewire::test(ManageLetterSignatories::class)
        ->callAction('create', ['name' => 'Neha Kapoor', 'designation' => 'Head of HR', 'status' => 'active', 'entity_applicability' => ['mode' => 'single', 'company' => $this->fe->id]])
        ->assertHasNoActionErrors();

    expect(LetterSignatory::query()->sole()->company_id)->toBe($this->fe->id);
});
