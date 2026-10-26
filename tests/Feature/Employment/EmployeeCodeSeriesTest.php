<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Exceptions\EmployeeCodeTakenException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeCodeSeries;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\EmployeeCodeSeries\EmployeeCodeSeriesResource;
use App\Filament\Resources\EmployeeCodeSeries\Pages\ManageEmployeeCodeSeries;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
| Employee code series: each company's joiners take the next code of its own series (prefix + padded number + suffix),
| companies without one use the tenant default series, and a tenant with no series keeps the employee.code settings.
| A code typed by hand is looked up across the whole tenant (every company, every access scope) before the hire goes on.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->india = Company::factory()->create(['name' => 'Markedge India']);
    $this->uk = Company::factory()->create(['name' => 'Markedge UK']);
    $this->labs = Company::factory()->create(['name' => 'Markedge Labs']);
    $this->actingAs(tenantUser($this->tenant, ['*']));
});

function hireInto(Company $company, array $employee = []): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => 'Test', 'last_name' => fake()->unique()->lastName()], ['joining_date' => '2026-10-01', ...$employee], ['company_id' => $company->id]);
}

it('gives each company the next code of its own series, and the default series to companies without one', function () {
    EmployeeCodeSeries::create(['company_id' => $this->india->id, 'prefix' => 'MKT-', 'suffix' => '-IN', 'digits' => 5]);
    EmployeeCodeSeries::create(['company_id' => $this->uk->id, 'prefix' => 'UK', 'digits' => 3, 'next_number' => 100]);
    EmployeeCodeSeries::create(['company_id' => null, 'prefix' => 'GRP', 'digits' => 4]);

    expect(hireInto($this->india)->employee_code)->toBe('MKT-00001-IN')
        ->and(hireInto($this->india)->employee_code)->toBe('MKT-00002-IN')
        ->and(hireInto($this->uk)->employee_code)->toBe('UK100')
        ->and(hireInto($this->labs)->employee_code)->toBe('GRP0001')
        ->and(hireInto($this->india)->employee_code)->toBe('MKT-00003-IN')
        ->and(hireInto($this->uk)->employee_code)->toBe('UK101');
});

it('keeps the settings pattern while no series exists', function () {
    expect(hireInto($this->india)->employee_code)->toBe('EMP00001')
        ->and(app(EmployeeCodeGenerator::class)->seriesFor($this->india->id))->toBeNull();
});

it('previews without taking the number, skips codes typed by hand, and continues past codes already in the pattern', function () {
    hireInto($this->india, ['employee_code' => 'MKT-00041-IN']);
    $series = EmployeeCodeSeries::create(['company_id' => $this->india->id, 'prefix' => 'MKT-', 'suffix' => '-IN', 'digits' => 5]);

    // Saving the series moved it past the highest code already used in its pattern.
    expect($series->fresh()->next_number)->toBe(42);

    $codes = app(EmployeeCodeGenerator::class);
    expect($codes->preview($this->india->id))->toBe('MKT-00042-IN')
        ->and($codes->preview($this->india->id))->toBe('MKT-00042-IN');

    hireInto($this->india, ['employee_code' => 'MKT-00042-IN']);
    expect(hireInto($this->india)->employee_code)->toBe('MKT-00043-IN');
});

it('refuses a code already held in the tenant, in any company and ignoring case', function () {
    hireInto($this->uk, ['employee_code' => 'EMP-7']);

    expect(fn () => hireInto($this->india, ['employee_code' => 'emp-7']))->toThrow(EmployeeCodeTakenException::class, 'emp-7 is already in use');
    expect(Employee::query()->count())->toBe(1);
});

it('looks a code up beyond the caller\'s access scope without naming someone they cannot see', function () {
    hireInto($this->uk, ['employee_code' => 'UK-SECRET']);
    $indiaHr = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($indiaHr, ['company' => [$this->india->id]]);
    $this->actingAs($indiaHr);

    Livewire::test(CreateEmployee::class)
        ->fillForm(['company_id' => $this->india->id, 'employee_code_mode' => 'manual', 'employee_code' => 'UK-SECRET'])
        ->call('create')
        ->assertHasFormErrors(['employee_code'])
        ->assertSee('already used by another employee');
});

it('hires through the wizard with an auto code from the company series and shows it beforehand', function () {
    EmployeeCodeSeries::create(['company_id' => $this->india->id, 'prefix' => 'MKT-', 'digits' => 4]);

    Livewire::test(CreateEmployee::class)
        ->fillForm(['first_name' => 'Priya', 'last_name' => 'Nair', 'joining_date' => '2026-10-01', 'company_id' => $this->india->id, 'audit_reason' => 'Campus hire'])
        ->assertSee('MKT-0001')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Employee::query()->whereHas('person', fn ($q) => $q->where('first_name', 'Priya'))->value('employee_code'))->toBe('MKT-0001');
});

it('hires through the wizard with a code typed by hand once the lookup finds it free', function () {
    EmployeeCodeSeries::create(['company_id' => $this->india->id, 'prefix' => 'MKT-', 'digits' => 4]);
    hireInto($this->india, ['employee_code' => 'OLD-1']);

    $wizard = Livewire::test(CreateEmployee::class)
        ->fillForm(['first_name' => 'Arjun', 'last_name' => 'Rao', 'joining_date' => '2026-10-01', 'company_id' => $this->india->id, 'employee_code_mode' => 'manual', 'employee_code' => 'old-1'])
        ->assertHasErrors(['data.employee_code'])
        ->assertSee('Employee code OLD-1 is already used by Test');

    $wizard->fillForm(['employee_code' => 'LEGACY-900'])->assertHasNoErrors(['data.employee_code'])->assertSee('LEGACY-900 is free')
        ->call('create')->assertHasNoFormErrors();

    expect(Employee::query()->where('employee_code', 'LEGACY-900')->exists())->toBeTrue()
        ->and(app(EmployeeCodeGenerator::class)->preview($this->india->id))->toBe('MKT-0001');
});

it('offers only the auto code where the series forbids typing one, and refuses a forged manual code', function () {
    EmployeeCodeSeries::create(['company_id' => $this->india->id, 'prefix' => 'MKT-', 'digits' => 4, 'allow_manual' => false]);

    $wizard = Livewire::test(CreateEmployee::class)->fillForm(['company_id' => $this->india->id])->assertDontSee('Enter manually');

    $wizard->fillForm(['first_name' => 'Kiran', 'last_name' => 'Das', 'joining_date' => '2026-10-01', 'employee_code_mode' => 'manual', 'employee_code' => 'FORGED-1'])
        ->call('create');

    expect(Employee::query()->where('employee_code', 'FORGED-1')->exists())->toBeFalse();
    // Switching to a company whose series allows it brings the choice back.
    $wizard->fillForm(['company_id' => $this->uk->id])->assertSee('Enter manually');
});

it('lets people setup admins configure one series per company and one default, audited', function () {
    $screen = fn () => Livewire::test(ManageEmployeeCodeSeries::class);
    $screen()->assertOk()->assertSee('No series is set up yet')
        ->callAction('create', ['company_id' => $this->india->id, 'prefix' => 'MKT-', 'suffix' => '-IN', 'digits' => 5, 'next_number' => 1001, 'allow_manual' => true])
        ->assertHasNoActionErrors()
        ->callAction('create', ['company_id' => EmployeeCodeSeriesResource::ALL_COMPANIES, 'prefix' => 'GRP', 'digits' => 4, 'next_number' => 1])
        ->assertHasNoActionErrors();
    $screen()->callAction('create', ['company_id' => $this->india->id, 'prefix' => 'X', 'digits' => 3, 'next_number' => 1])->assertHasActionErrors(['company_id']);
    $screen()->callAction('create', ['company_id' => EmployeeCodeSeriesResource::ALL_COMPANIES, 'prefix' => 'Y', 'digits' => 3, 'next_number' => 1])->assertHasActionErrors(['company_id']);
    $screen()->callAction('create', ['company_id' => $this->uk->id, 'prefix' => 'UK/', 'digits' => 3, 'next_number' => 1])->assertHasActionErrors(['prefix']);
    $screen()->assertSee('MKT-#####-IN')->assertSee('MKT-01001-IN')->assertSee('GRP0001');

    $india = EmployeeCodeSeries::query()->where('company_id', $this->india->id)->sole();
    expect(EmployeeCodeSeries::query()->whereNull('company_id')->value('prefix'))->toBe('GRP')
        ->and($india->next_number)->toBe(1001);

    Livewire::test(ManageEmployeeCodeSeries::class)
        ->callAction(TestAction::make('edit')->table($india), ['prefix' => 'MEI-', 'audit_reason' => 'Rebrand'])
        ->assertHasNoActionErrors();

    expect(hireInto($this->india)->employee_code)->toBe('MEI-01001-IN')
        ->and(AuditEvent::query()->where('entity_type', EmployeeCodeSeries::class)->where('entity_id', (string) $india->id)->where('action', 'UPDATE')->value('reason'))->toBe('Rebrand');
});

it('keeps the series screen to people setup permissions and each tenant to its own series', function () {
    EmployeeCodeSeries::create(['company_id' => $this->india->id, 'prefix' => 'MKT-', 'digits' => 4]);

    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(EmployeeCodeSeriesResource::getUrl('index'))->assertForbidden();

    $other = provisionTenant('Other');
    actAsTenant($other);
    $company = Company::factory()->create();
    expect(EmployeeCodeSeries::query()->count())->toBe(0)
        ->and(app(EmployeeCodeGenerator::class)->next($company->id))->toBe('EMP00001');
});
