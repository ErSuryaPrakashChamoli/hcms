<?php

use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Models\SalaryStructureComponent;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationRanges;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* Phase 11.2: versioned compensation structures (§6) and grade pay ranges (§8, §25). */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->preparer = tenantUser($this->tenant, ['compensation.configure', 'compensation.view']);
    $this->approver = tenantUser($this->tenant, ['compensation.approve', 'compensation.view']);
    $this->structures = app(CompensationStructures::class);
    $this->ranges = app(CompensationRanges::class);
    $this->standard = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $this->basic = SalaryComponent::query()->where('code', 'BASIC')->firstOrFail();
    $this->grade = Grade::factory()->create(['name' => 'G5', 'code' => 'G5']);
});

it('runs a structure version through draft, approval by another person and immutability; a correction is a new version', function () {
    $structure = $this->structures->create(['name' => 'Sales', 'code' => 'sales', 'effective_from' => '2026-10-01', 'components' => [['salary_component_id' => $this->basic->id, 'sort_order' => 10]]], $this->preparer);
    $v1 = $structure->versions()->sole();
    expect($structure->code)->toBe('SALES')->and($v1->status)->toBe('draft')->and($v1->components()->count())->toBe(1);

    $this->structures->submit($v1, $this->preparer);
    expect(fn () => $this->structures->approve($v1->refresh(), $this->preparer))->toThrow(RuntimeException::class, 'compensation.approve');
    $both = tenantUser($this->tenant, ['compensation.configure', 'compensation.approve']);
    $v1b = $this->structures->create(['name' => 'Ops', 'code' => 'ops', 'effective_from' => '2026-10-01', 'components' => [['salary_component_id' => $this->basic->id]]], $both)->versions()->sole();
    $this->structures->submit($v1b, $both);
    expect(fn () => $this->structures->approve($v1b->refresh(), $both))->toThrow(RuntimeException::class, 'prepared a structure version cannot approve');

    $v1 = $this->structures->approve($v1->refresh(), $this->approver);
    expect($v1->status)->toBe('scheduled')->and($v1->checksum)->toHaveLength(64)
        ->and(fn () => $v1->update(['currency' => 'USD']))->toThrow(RuntimeException::class, 'Only a draft structure version is edited')
        ->and(fn () => SalaryStructureComponent::query()->where('salary_structure_version_id', $v1->id)->first()->update(['sort_order' => 5]))->toThrow(RuntimeException::class, 'approved versions are immutable')
        ->and(fn () => SalaryStructureComponent::query()->where('salary_structure_version_id', $v1->id)->first()->delete())->toThrow(RuntimeException::class, 'approved versions are immutable')
        ->and(fn () => $v1->delete())->toThrow(RuntimeException::class, 'never deleted');

    // Correction: a new version from a later date, copied from the approved one.
    $v2 = $this->structures->newVersion($structure, $this->preparer, '2027-01-01');
    expect($v2->version)->toBe(2)->and($v2->components()->count())->toBe(1)
        ->and(fn () => $this->structures->newVersion($structure, $this->preparer, '2027-02-01'))->toThrow(RuntimeException::class, 'already has a version in preparation');
    $this->structures->submit($v2, $this->preparer);
    $this->structures->approve($v2->refresh(), $this->approver);
    expect($v1->refresh()->effective_to->toDateString())->toBe('2026-12-31')->and($v2->refresh()->status)->toBe('scheduled');

    // The effective-date processor activates each on its date and supersedes the one before.
    expect($this->structures->promoteDue('2026-10-01'))->toBe(1)->and($v1->refresh()->status)->toBe('active')
        ->and($this->structures->promoteDue('2027-01-01'))->toBe(1)->and($v2->refresh()->status)->toBe('active')->and($v1->refresh()->status)->toBe('superseded');
});

it('keeps historical payroll on the composition in force at the time and splits a period at a version boundary', function () {
    $employee = salariedEmployee(600000);
    $august = PayrollPeriod::for($this->company, 2026, 8);
    $before = app(PayrollCalculator::class)->calculate($employee, $august);
    expect($before->amount('BASIC'))->toBe(20000.0);

    // A new version from 16 September pays BASIC at 50% of monthly CTC.
    $v2 = $this->structures->newVersion($this->standard, $this->preparer, '2026-09-16');
    $rows = $v2->components()->get()->map(fn ($c) => $c->only(['salary_component_id', 'formula_override', 'pay_nature', 'frequency', 'sort_order']))->all();
    $rows = array_map(fn ($r) => $r['salary_component_id'] === $this->basic->id ? ['formula_override' => 'ctc_monthly * 0.5'] + $r : $r, $rows);
    $this->structures->updateDraft($v2, ['components' => $rows], $this->preparer);
    $this->structures->submit($v2, $this->preparer);
    $this->structures->approve($v2->refresh(), $this->approver);

    $after = app(PayrollCalculator::class)->calculate($employee->fresh(), $august);
    expect($after->amount('BASIC'))->toBe($before->amount('BASIC'))   // history recalculates on version 1
        ->and($after->inputs['compensation']['fingerprint'])->toBe($before->inputs['compensation']['fingerprint']);

    $september = app(PayrollCalculator::class)->calculate($employee->fresh(), PayrollPeriod::for($this->company, 2026, 9));
    expect($september->inputs['segments'])->toHaveCount(2)
        ->and(collect($september->inputs['segments'])->pluck('from')->all())->toBe(['2026-09-01', '2026-09-16'])
        ->and($september->amount('BASIC'))->toBe(round(20000 * 15 / 30, 2) + round(25000 * 15 / 30, 2));
});

it('refuses a structure version that would reach into a finalized payroll period or before an approved version', function () {
    EmployeeBankAccount::create(['employee_id' => salariedEmployee(600000)->id, 'account_holder_name' => 'X', 'bank_name' => 'B', 'account_number' => '1', 'ifsc' => 'BANK0000001', 'is_primary' => true]);
    $payroll = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $runs = app(PayrollRuns::class);
    $runs->finalize($runs->approve($runs->validate($runs->calculate($runs->open($this->company, 2026, 9))), $payroll), $payroll);

    $v = $this->structures->newVersion($this->standard, $this->preparer, '2026-09-30');
    $this->structures->submit($v, $this->preparer);
    expect(fn () => $this->structures->approve($v->refresh(), $this->approver))->toThrow(RuntimeException::class, 'closed payroll period');
    $this->structures->returnToDraft($v->refresh(), $this->approver, 'Pick October');
    $this->structures->updateDraft($v->refresh(), ['effective_from' => '2026-10-01'], $this->preparer);
    $this->structures->submit($v, $this->preparer);
    expect($this->structures->approve($v->refresh(), $this->approver)->status)->toBe('scheduled');
});

it('validates proposals against the structure version in force: existence, applicability and its components', function () {
    $employee = salariedEmployee(600000);
    $actors = compensationActors();
    $changes = app(CompensationChanges::class);
    $other = Company::factory()->create();
    $structure = $this->structures->create(['name' => 'Other co', 'code' => 'otherco', 'effective_from' => '2026-11-01', 'company_id' => $other->id, 'components' => [['salary_component_id' => $this->basic->id]]], $this->preparer);
    $proposal = fn (array $data) => $changes->propose($employee, $data + ['change_type' => 'annual_increment', 'effective_from' => '2026-12-01', 'salary_structure_id' => $this->standard->id, 'ctc_annual' => 650000, 'reason' => 'x'], $actors['proposer']);

    expect(fn () => $proposal(['salary_structure_id' => $structure->id]))->toThrow(RuntimeException::class, 'no approved version in force');
    $v = $structure->versions()->sole();
    $this->structures->submit($v, $this->preparer);
    $this->structures->approve($v->refresh(), $this->approver);
    expect(fn () => $proposal(['salary_structure_id' => $structure->id]))->toThrow(RuntimeException::class, "does not apply to this employee's company")
        ->and(fn () => $proposal(['component_values' => ['NOPE' => 10]]))->toThrow(RuntimeException::class, 'Component [NOPE] is not part of structure STANDARD v1')
        ->and(fn () => $proposal(['ctc_annual' => -5]))->toThrow(RuntimeException::class, 'Annual CTC must be positive')
        ->and(fn () => $proposal(['currency' => 'XYZ']))->toThrow(RuntimeException::class, 'not a supported ISO 4217 currency')
        ->and(fn () => $proposal(['effective_from' => 'not a date']))->toThrow(RuntimeException::class, 'valid effective date')
        ->and($proposal([])->status)->toBe('draft');
});

it('validates ranges: order, sign, currency, dates and the configurable range model', function () {
    $base = ['grade_id' => $this->grade->id, 'currency' => 'INR', 'minimum' => 500000, 'midpoint' => 650000, 'maximum' => 800000, 'effective_from' => '2026-04-01'];
    $create = fn (array $data) => $this->ranges->create($data + $base, $this->preparer);

    expect(fn () => $create(['minimum' => 900000]))->toThrow(RuntimeException::class, 'minimum must not exceed the maximum')
        ->and(fn () => $create(['midpoint' => 900000]))->toThrow(RuntimeException::class, 'midpoint must lie between')
        ->and(fn () => $create(['minimum' => -1]))->toThrow(RuntimeException::class, 'must be zero or more')
        ->and(fn () => $create(['currency' => 'RUPEE']))->toThrow(RuntimeException::class, 'ISO 4217')
        ->and(fn () => $create(['effective_to' => '2026-03-01']))->toThrow(RuntimeException::class, 'ends before it starts')
        ->and(fn () => $create(['midpoint' => null]))->toThrow(RuntimeException::class, 'needs a midpoint')
        ->and(fn () => $create(['grade_id' => 999999]))->toThrow(RuntimeException::class, 'does not exist');

    config(['peopleos.compensation.range_model' => 'min_max']);
    expect($create(['midpoint' => null, 'minimum' => 500000, 'maximum' => 500000])->midpoint)->toBeNull();
});

it('approves ranges by a second person, never overlaps a definition and closes the previous version', function () {
    $range = $this->ranges->create(['grade_id' => $this->grade->id, 'currency' => 'INR', 'minimum' => 500000, 'midpoint' => 650000, 'maximum' => 800000, 'effective_from' => '2026-04-01'], $this->preparer);
    $this->ranges->submit($range, $this->preparer);
    expect(fn () => $this->ranges->approve($range->refresh(), $this->preparer))->toThrow(RuntimeException::class);
    $range = $this->ranges->approve($range->refresh(), $this->approver);
    expect($range->status)->toBe('approved')->and(fn () => $range->update(['maximum' => 1]))->toThrow(RuntimeException::class, 'never changes');

    // Same applicability starting on or before the approved one: refused. Later: closes it.
    $same = $this->ranges->create(['grade_id' => $this->grade->id, 'currency' => 'INR', 'minimum' => 1, 'midpoint' => 2, 'maximum' => 3, 'effective_from' => '2026-04-01'], $this->preparer);
    $this->ranges->submit($same, $this->preparer);
    expect(fn () => $this->ranges->approve($same->refresh(), $this->approver))->toThrow(RuntimeException::class, 'already starts on 2026-04-01');
    $next = $this->ranges->create(['grade_id' => $this->grade->id, 'currency' => 'INR', 'minimum' => 550000, 'midpoint' => 700000, 'maximum' => 850000, 'effective_from' => '2027-04-01'], $this->preparer);
    $this->ranges->submit($next, $this->preparer);
    $this->ranges->approve($next->refresh(), $this->approver);
    expect($range->refresh()->status)->toBe('superseded')->and($range->effective_to->toDateString())->toBe('2027-03-31')
        ->and($next->version)->toBe(3)
        ->and($this->ranges->rangeFor($this->grade->id, '2026-10-01')->id)->toBe($range->id)
        ->and($this->ranges->rangeFor($this->grade->id, '2027-05-01')->id)->toBe($next->id);
});

it('picks the most specific range and computes range position and compa-ratio without dividing by zero', function () {
    $approve = function (array $data) {
        $r = $this->ranges->create($data + ['grade_id' => $this->grade->id, 'currency' => 'INR', 'effective_from' => '2026-04-01'], $this->preparer);
        $this->ranges->submit($r, $this->preparer);

        return $this->ranges->approve($r->refresh(), $this->approver);
    };
    $gradeWide = $approve(['minimum' => 500000, 'midpoint' => 600000, 'maximum' => 700000]);
    $designation = Designation::factory()->create();
    $specific = $approve(['designation_id' => $designation->id, 'minimum' => 800000, 'midpoint' => 900000, 'maximum' => 1000000]);
    $monthly = $approve(['company_id' => $this->company->id, 'frequency' => 'monthly', 'currency' => 'USD', 'minimum' => 1000, 'midpoint' => 1500, 'maximum' => 2000]);

    expect($this->ranges->rangeFor($this->grade->id, '2026-10-01')->id)->toBe($gradeWide->id)
        ->and($this->ranges->rangeFor($this->grade->id, '2026-10-01', designationId: $designation->id)->id)->toBe($specific->id)
        ->and($this->ranges->rangeFor($this->grade->id, '2026-10-01', companyId: $this->company->id, currency: 'USD')->id)->toBe($monthly->id);

    $p = $this->ranges->position(650000, $gradeWide, 'INR');
    expect($p['band'])->toBe('within')->and($p['range_position'])->toBe(0.75)->and($p['compa_ratio'])->toBe(1.0833);
    expect($this->ranges->position(450000, $gradeWide, 'INR')['band'])->toBe('below')
        ->and($this->ranges->position(750000, $gradeWide, 'INR')['band'])->toBe('above')
        ->and($this->ranges->position(650000, $gradeWide, 'USD'))->toMatchArray(['band' => null, 'compa_ratio' => null])
        ->and($this->ranges->position(18000, $monthly, 'USD'))->toMatchArray(['band' => 'within', 'range_position' => 0.5, 'compa_ratio' => 1.0])   // monthly range ×12
        ->and($this->ranges->position(1, null, 'INR')['note'])->toBe('No approved range applies.');

    config(['peopleos.compensation.range_model' => 'min_max']);
    $flat = $approve(['salary_structure_id' => $this->standard->id, 'minimum' => 600000, 'midpoint' => null, 'maximum' => 600000]);
    $flatPosition = $this->ranges->position(600000, $flat, 'INR');
    expect($flatPosition)->toMatchArray(['band' => 'within', 'range_position' => null, 'compa_ratio' => null])->and($flatPosition['note'])->toContain('Minimum equals maximum');
});
