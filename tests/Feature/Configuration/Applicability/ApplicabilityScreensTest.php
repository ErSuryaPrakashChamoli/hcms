<?php

use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Models\ConfigurationApplicability;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Filament\Resources\Departments\Pages\ListDepartments;
use App\Filament\Resources\Designations\Pages\CreateDesignation;
use App\Filament\Resources\Policies\Pages\ListPolicies;
use App\Filament\Resources\Skills\Pages\ManageSkills;
use Livewire\Livewire;

/*
| The screens of the applicability framework (Part 8): every registered configuration screen gets the same "Applies to"
| step, column, filters and Companies actions through the shared pages, without per-module code.
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->th = Company::factory()->create(['name' => 'Third Labs', 'code' => 'TH']);
});

it('lists where each department applies and filters by entity and by shared or single', function () {
    $hr = Department::factory()->create(['name' => 'Human Resources', 'code' => 'HR', 'company_id' => $this->mk->id]);
    $fin = Department::factory()->create(['name' => 'Finance', 'code' => 'FIN', 'company_id' => $this->fe->id]);
    $all = Department::factory()->create(['name' => 'Legal', 'code' => 'LEG', 'company_id' => null]);
    app(EntityApplicability::class)->apply($hr, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin);

    Livewire::test(ListDepartments::class)
        ->assertOk()
        ->assertSee('Markedge Technologies, FynnEdge Advisory')
        ->assertSee('FynnEdge Advisory only')
        ->assertSee('All entities')
        ->filterTable('cfg_entity', $this->fe->id)
        ->assertCanSeeTableRecords([$hr, $fin, $all])
        ->filterTable('cfg_entity', $this->th->id)
        ->assertCanSeeTableRecords([$all])->assertCanNotSeeTableRecords([$hr, $fin])
        ->resetTableFilters()
        ->filterTable('cfg_applicability', 'single')
        ->assertCanSeeTableRecords([$fin])->assertCanNotSeeTableRecords([$hr, $all])
        ->filterTable('cfg_applicability', 'shared')
        ->assertCanSeeTableRecords([$hr, $all])->assertCanNotSeeTableRecords([$fin]);
});

it('changes where a department applies from the list, after a review, with confirmation for several companies', function () {
    $hr = Department::factory()->create(['name' => 'Human Resources', 'code' => 'HR', 'company_id' => $this->mk->id]);

    Livewire::test(ListDepartments::class)
        ->callTableAction('manageApplicability', $hr, [
            'mode' => 'selected', 'companies' => [$this->mk->id, $this->fe->id], 'effective_from' => '2026-11-23', 'confirmed' => true, 'audit_reason' => 'Group HR',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Applies to Markedge Technologies, FynnEdge Advisory');

    expect(app(EntityApplicability::class)->reach($hr))->toBe([$this->mk->id, $this->fe->id]);

    // Without the confirmation the multi-company change is not made.
    Livewire::test(ListDepartments::class)
        ->callTableAction('manageApplicability', $hr, ['mode' => 'all', 'include_future' => true, 'effective_from' => '2026-11-23', 'audit_reason' => 'Everyone'])
        ->assertHasTableActionErrors(['confirmed']);
    expect(app(EntityApplicability::class)->reach($hr))->toBe([$this->mk->id, $this->fe->id]);

    Livewire::test(EditDepartment::class, ['record' => $hr->getRouteKey()])->assertOk()->assertSee('Applies to')->assertSee('Markedge Technologies, FynnEdge Advisory')->assertSee('Applies to…')->assertDontSee('Company values…');
});

it('asks where a new designation applies while creating it, and writes nothing for "All entities"', function () {
    Livewire::test(CreateDesignation::class)
        ->fillForm(['name' => 'Consultant', 'code' => 'CONS', 'entity_applicability' => ['mode' => 'selected', 'companies' => [$this->mk->id, $this->fe->id]]])
        ->call('create')
        ->assertHasNoFormErrors();
    $consultant = Designation::query()->where('code', 'CONS')->sole();
    expect(app(EntityApplicability::class)->reach($consultant))->toBe([$this->mk->id, $this->fe->id]);

    Livewire::test(CreateDesignation::class)
        ->fillForm(['name' => 'Partner', 'code' => 'PTNR', 'entity_applicability' => ['mode' => 'all', 'include_future' => true]])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(app(EntityApplicability::class)->isExplicit(Designation::query()->where('code', 'PTNR')->sole()))->toBeFalse()
        ->and(ConfigurationApplicability::query()->count())->toBe(1);
});

it('sets a company value of a shared policy from the policy list, shown as the company\'s own', function () {
    $policies = app(Policies::class);
    $policy = Policy::create(['type' => 'attendance', 'name' => 'Group attendance', 'code' => 'GRP_ATT']);
    $policies->draft($policy, ['grace_minutes' => 10]);
    $policies->publish($policy, '2026-01-01');

    Livewire::test(ListPolicies::class)
        ->callTableAction('companyValues', $policy, [
            'company_id' => $this->fe->id, 'operation' => 'set', 'field' => 'grace_minutes', 'value_number' => 20, 'effective_from' => '2026-11-23', 'audit_reason' => 'FynnEdge shifts',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Company value saved');

    expect(ConfigurationOverride::query()->sole()->only(['company_id', 'field', 'value']))->toBe(['company_id' => $this->fe->id, 'field' => 'grace_minutes', 'value' => 20]);
    Livewire::test(ListPolicies::class)->assertSee('Own values: FynnEdge Advisory');
});

it('offers the Companies actions and the "Applies to" choice on side-drawer screens too', function () {
    Livewire::test(ManageSkills::class)->assertOk()->assertSee('Applies to');
});
