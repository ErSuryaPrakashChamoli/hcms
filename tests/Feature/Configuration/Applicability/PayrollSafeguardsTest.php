<?php

use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;

require_once __DIR__.'/../../Payroll/PayrollTestHelpers.php';

/*
| Payroll and compensation safeguards of the applicability framework (Part 7 of the stages, Part 10): a salary structure
| limited to some companies is never assigned to another company's employee; companies it applies to are unaffected.
*/

beforeEach(function () {
    $this->travelTo('2026-11-23 10:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->hire = function (Company $company) {
        $employee = app(HireEmployeeAction::class)->handle(['first_name' => fake()->firstName(), 'last_name' => 'Pay'], ['joining_date' => '2026-11-01'], ['company_id' => $company->id]);

        return forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2026-11-01']);
    };
});

it('never assigns a salary structure limited to other companies', function () {
    $structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    app(EntityApplicability::class)->apply($structure, ApplicabilitySelection::single($this->mk->id), $this->admin);

    expect(fn () => compensate(($this->hire)($this->fe), 600000, '2026-11-23'))
        ->toThrow(CompensationRuleViolation::class, "Structure STANDARD does not apply to this employee's company.");
    expect(compensate(($this->hire)($this->mk), 600000, '2026-11-23')->salary_structure_id)->toBe($structure->id);
});
