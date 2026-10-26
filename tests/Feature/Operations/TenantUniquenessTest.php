<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\LegalEntity;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Support\TenantUnique;
use App\Support\Tenancy\Exceptions\MissingTenantException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Livewire;

/*
| SaaS.8: uniqueness on tenant-owned tables is checked within the tenant only. Filament's unique() is a raw database rule
| that ignored the tenant scope: an employee save failed when another tenant held the same code, and the create form told
| one tenant which codes (and so roughly how many employees) another tenant had.
*/

function hireIn(string $code): Employee
{
    $company = Company::factory()->create();
    $department = Department::factory()->create();

    return app(HireEmployeeAction::class)->handle(['first_name' => 'Test', 'last_name' => $code], ['joining_date' => '2026-01-05', 'employee_code' => $code],
        ['company_id' => $company->id, 'department_id' => $department->id]);
}

beforeEach(function () {
    $this->alpha = provisionTenant('Alpha');
    $this->beta = provisionTenant('Beta');
    actAsTenant($this->alpha);
    $this->alphaEmployee = hireIn('SHARED-7');
    actAsTenant($this->beta);
    $this->betaEmployee = hireIn('BETA-1');
    $this->betaOther = hireIn('BETA-2');
    $this->betaAdmin = tenantUser($this->beta, ['*']);
    $this->actingAs($this->betaAdmin);
});

it('accepts on create a code that only another tenant holds, so the form reveals nothing about other tenants', function () {
    Livewire::test(CreateEmployee::class)->fillForm(['employee_code_mode' => 'manual', 'employee_code' => 'SHARED-7'])->call('create')->assertHasNoFormErrors(['employee_code']);
});

it('still refuses on create a code the same tenant already holds', function () {
    Livewire::test(CreateEmployee::class)->fillForm(['employee_code_mode' => 'manual', 'employee_code' => 'BETA-1'])->call('create')->assertHasFormErrors(['employee_code']);
});

it('saves an employee whose code another tenant also uses, and refuses a duplicate inside the tenant', function () {
    $this->betaEmployee->update(['employee_code' => 'SHARED-7']);

    Livewire::test(EditEmployee::class, ['record' => $this->betaEmployee->id])->fillForm(['employee_code' => 'SHARED-7', 'audit_reason' => 'Correction'])
        ->call('save')->assertHasNoFormErrors(['employee_code']);
    Livewire::test(EditEmployee::class, ['record' => $this->betaEmployee->id])->fillForm(['employee_code' => 'BETA-2', 'audit_reason' => 'Correction'])
        ->call('save')->assertHasFormErrors(['employee_code']);

    actAsTenant($this->alpha);
    expect($this->alphaEmployee->fresh()->employee_code)->toBe('SHARED-7');
});

it('scopes the legal-entity code rule to the tenant and fails closed without one', function () {
    actAsTenant($this->alpha);
    LegalEntity::query()->create(['company_id' => Company::factory()->create()->id, 'code' => 'LE-ONE', 'legal_name' => 'Alpha Pvt Ltd', 'country' => 'IN', 'status' => 'active', 'effective_from' => '2026-01-01']);
    $rule = fn () => ['code' => [(TenantUnique::rule())(Rule::unique('legal_entities', 'code'))]];

    expect(Validator::make(['code' => 'LE-ONE'], $rule())->fails())->toBeTrue();
    actAsTenant($this->beta);
    expect(Validator::make(['code' => 'LE-ONE'], $rule())->fails())->toBeFalse();
    actAsTenant(null);
    expect($rule)->toThrow(MissingTenantException::class);
});
