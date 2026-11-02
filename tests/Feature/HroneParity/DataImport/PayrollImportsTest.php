<?php

use App\Domain\Compensation\Imports\PayGroupChangeImports;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TaxIncomeEntry;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\InvestmentProofs;
use App\Domain\Compliance\Services\TaxComputer;
use App\Domain\Compliance\Services\TaxIncomeEntries;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollComputation;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

require_once __DIR__.'/Di4Helpers.php';

/* Data import DI-4 (Payroll): tax declarations, previous salary, statutory details and the compensation uploads. */

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->tax = fn (Employee $employee, int $month = 10): PayrollComputation => app(PayrollCalculator::class)->calculate($employee->refresh(), PayrollPeriod::for($this->company, 2026, $month));
});

/** @return list<string> the upload types of package DI-4 */
function di4Types(): array
{
    return ['tax_12baa', 'previous_employment', 'previous_salaries', 'investments', 'hra_declarations', 'employee_statutory', 'loans', 'variable_pay',
        'salary_arrears', 'reimbursement_claims', 'reimbursement_opening', 'reimbursement_bills', 'settlements', 'pay_group_changes', 'salary_components'];
}

it('registers every DI-4 upload under Payroll (previous employment under Workforce) with its format in field order and two sample rows', function () {
    foreach (di4Types() as $type) {
        expect(RowImportTypes::has($type))->toBeTrue($type)
            ->and(RowImportTypes::TYPES[$type][3])->toBe($type === 'previous_employment' ? 'workforce' : 'payroll');
        $service = ImportTypes::service($type);
        expect($service)->toBeInstanceOf(RowImports::class);
        $labels = array_column($service::fields(), 0);
        expect(array_column($service->format()['columns'], 0))->toBe($labels, "{$type}: format columns follow the fields")
            ->and(count(iterator_to_array(Reader::fromString($service->template())->getRecords(), false)))->toBeGreaterThanOrEqual(3, "{$type}: two example rows");
    }
    // Every process of these types now shows on the page; PF arrear (it would need the EPFO arrear return) stays hidden.
    $built = collect(ImportCatalogue::PROCESSES)->filter(fn ($p) => in_array($p['type'], di4Types(), true))->keys()->all();
    expect($built)->toContain('payroll.form_12baa', 'workforce.previous_employment', 'payroll.salary_structure_manual', 'payroll.change_pay_group', 'payroll.reimbursement_bills')
        ->and(collect($built)->every(fn ($key) => ImportCatalogue::built(ImportCatalogue::find($key))))->toBeTrue()
        ->and(ImportCatalogue::built(ImportCatalogue::find('payroll.pf_arrear')))->toBeFalse()
        ->and(ImportCatalogue::find('payroll.perquisites_auto')['columns'])->not->toContain('recovered');

    // Statutory details need employee.sensitive.update besides employee.import; tax uploads need payroll.manage.
    $payroll = tenantUser($this->tenant, ['employee.import', 'payroll.manage']);
    expect(ImportTypes::allowed($payroll, 'investments'))->toBeTrue()
        ->and(ImportTypes::allowed($payroll, 'employee_statutory'))->toBeFalse()
        ->and(ImportTypes::allowed($payroll, 'salary_components'))->toBeFalse()
        ->and(ImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'compensation.cycles']), 'salary_components'))->toBeTrue();
});

it('records 12BAA tax credits for TDS and counts them only when the TDS rule allows it', function () {
    $asha = di4Employee(1800000);
    $ravi = di4Employee(900000);
    $csv = "Employee code,TDS or TCS,Provision,Deducted or collected on,Amount paid or received,Tax deducted or collected,Deductor or collector,Deductor TAN,Reference\n"
        ."{$asha->employee_code},TDS,393,2026-07-31,50000,5000,State Bank of India,MUMS12345A,FD\n"
        ."{$ravi->employee_code},tcs,394,2026-08-10,\"8,00,000\",40000,Global Travels,DELG54321B,\n"
        ."{$asha->employee_code},TDS,393,2026-07-31,50000,5000,State Bank of India,MUMS12345A,again\n"
        ."{$ravi->employee_code},TDS,393,2026-08-10,1000,0,Bank,BADTAN,\n"
        ."NOBODY,TDS,393,2027-12-01,1000,100,,,\n";
    $import = di4Stage('tax_12baa', $csv);
    $rows = di4Rows($import);

    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(3)
        ->and($rows[3]->errors[0])->toContain('row 1')
        ->and(implode(' ', $rows[4]->errors))->toContain('above zero')->toContain('ABCD12345E')->not->toContain('BADTAN')
        ->and(implode(' ', $rows[5]->errors))->toContain('does not exist')->toContain('future');

    di4Run($import);
    $credit = TaxIncomeEntry::query()->where('employee_id', $asha->id)->where('kind', 'tax_credit')->sole();
    expect($credit->financial_year)->toBe('2026-27')->and((float) $credit->tds_deducted)->toBe(5000.0)->and($credit->credit_type)->toBe('tds')
        ->and($credit->deductor_tan)->toBe('MUMS12345A')->and((float) $credit->taxable_amount)->toBe(0.0)
        ->and(app(TaxIncomeEntries::class)->totals($ravi->id, '2026-27')['tax_credit'])->toBe(40000.0);

    // Decision: the declared tax counts as already deducted (like the previous employer's TDS) …
    $c = ($this->tax)($asha);
    expect($c->inputs['tax']['tax_credit_counted'])->toBe(5000.0)
        ->and($c->inputs['tax'])->not->toHaveKey('tax_credit_not_counted')
        ->and(($this->tax)($ravi)->inputs['tax']['tax_credit_counted'])->toBe(40000.0)
        ->and(($this->tax)(di4Employee(700000))->inputs['tax'])->not->toHaveKey('tax_credit_counted'); // no credit: the basis is as before
    // … unless a TDS rule version turns the credit off for its period.
    $rule = app(ComplianceRules::class)->resolve('TDS', $c->period->paymentDate());
    $off = $rule->replicate();
    $off->parameters = array_merge($rule->parameters, ['other_tds_tcs_credit' => false]);
    $notCounted = app(TaxComputer::class)->monthlyTds($c, $off);
    expect($notCounted['basis']['tax_credit_not_counted'])->toBe(5000.0)
        ->and($notCounted['basis']['already_deducted'])->toBe($c->inputs['tax']['already_deducted'] - 5000.0);

    // The same certificate line again is skipped (rows only add).
    expect(RowImportTypes::TYPES['tax_12baa'][0]::intents())->toBeFalse()
        ->and(di4Stage('tax_12baa', $csv)->skip_count)->toBe(2);
});

it('records months paid before go-live for TDS, replaces a month on an update and refuses a month PeopleOS paid', function () {
    $asha = di4Employee(1200000);
    $before = ($this->tax)($asha)->inputs['tax'];
    $csv = "Employee code,Salary month,Gross earnings,Taxable earnings,Basic + DA,HRA,Income tax deducted,Employee PF deducted,Professional tax deducted,Paid on,Reference\n"
        ."{$asha->employee_code},2026-04,100000,95000,40000,20000,6000,4800,200,,old\n"
        ."{$asha->employee_code},2026-05,100000,,40000,20000,6000,4800,200,2026-05-31,old\n"
        ."{$asha->employee_code},2026-12,100000,,,,,,,,\n"
        ."{$asha->employee_code},2026-06,100000,120000,70000,40000,,,,,\n"
        ."{$asha->employee_code},2026-04,90000,,,,,,,,\n";
    $import = di4Stage('previous_salaries', $csv);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(3)
        ->and($rows[3]->errors[0])->toContain('not started')
        ->and(implode(' ', $rows[4]->errors))->toContain('more than the gross')
        ->and($rows[5]->errors[0])->toContain('row 1');

    di4Run($import);
    $totals = app(TaxIncomeEntries::class)->totals($asha->id, '2026-27');
    expect($totals)->toMatchArray(['outside_taxable' => 195000.0, 'outside_tds' => 12000.0, 'outside_pf' => 9600.0, 'outside_pt' => 400.0, 'outside_basic' => 80000.0, 'outside_hra' => 40000.0, 'previous_months' => 2]);
    $after = ($this->tax)($asha)->inputs['tax'];
    expect($after['already_deducted'])->toBe($before['already_deducted'] + 12000.0)
        ->and($after['previous_salary_months'])->toBe(2)
        ->and($after['projected_gross'])->toBe($before['projected_gross'] + 195000.0);

    // New only: a month on file is skipped; update: it is replaced (the earlier entry cancelled, never edited).
    $april = "Employee code,Salary month,Gross earnings\n{$asha->employee_code},2026-04,110000\n";
    expect(di4Stage('previous_salaries', $april, ['mode' => 'new'])->skip_count)->toBe(1);
    $replaced = di4Run(di4Stage('previous_salaries', $april, ['mode' => 'update']));
    expect($replaced->update_count)->toBe(1)
        ->and(TaxIncomeEntry::query()->where('employee_id', $asha->id)->where('kind', 'previous_salary')->whereDate('pay_month', '2026-04-01')->pluck('status')->sort()->values()->all())->toBe(['active', 'cancelled'])
        ->and(app(TaxIncomeEntries::class)->totals($asha->id, '2026-27')['outside_taxable'])->toBe(210000.0);

    // A month PeopleOS has finalized is never recorded again.
    di4Finalize(9, $this->hr, $this->approver);
    $september = di4Stage('previous_salaries', "Employee code,Salary month,Gross earnings\n{$asha->employee_code},2026-09,100000\n");
    expect(di4Rows($september)[1]->errors[0])->toContain('PeopleOS has already paid September 2026');
});

it('saves investment items into the declaration with the screen\'s rules, never changing a verified proof', function () {
    $asha = di4Employee(1500000, ['payroll.payslip']);
    $ravi = di4Employee(900000);
    $csv = "Employee code,Financial year,Investment item,Amount declared,Description,Tax regime\n"
        ."{$asha->employee_code},2026-27,80C.ppf,\"1,50,000\",,old\n"
        ."{$asha->employee_code},2026-27,80D.self_family,25000,,\n"
        ."{$ravi->employee_code},2026-27,80C.nothing,1000,,\n"
        ."{$ravi->employee_code},2026-27,80C.other,5000,,\n"
        ."{$ravi->employee_code},2031-32,80C.ppf,5000,,\n"
        ."{$asha->employee_code},2026-27,80c.PPF,1000,,\n";
    $import = di4Stage('investments', $csv);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(4)
        ->and($rows[3]->errors[0])->toContain('not in the list')
        ->and($rows[4]->errors[0])->toContain('something else')
        ->and($rows[5]->errors[0])->toContain('Financial year must be one of')
        ->and($rows[6]->errors[0])->toContain('row 1');

    di4Run($import);
    $declaration = EmployeeTaxDeclaration::query()->where('employee_id', $asha->id)->where('financial_year', '2026-27')->sole();
    expect($declaration->status)->toBe('submitted')->and($declaration->regime)->toBe('old')
        ->and($declaration->amount('80C'))->toBe(150000.0)->and($declaration->amount('80D'))->toBe(25000.0)
        ->and(TdsEmployeeInvestment::query()->where('employee_id', $asha->id)->pluck('item_key')->sort()->values()->all())->toBe(['80C.ppf', '80D.self_family']);

    // Payroll verifies the PPF proof: an upload never changes it; another item is updated on "update" only, and the rest stays.
    app(InvestmentProofs::class)->review(TdsEmployeeInvestment::query()->where('employee_id', $asha->id)->where('item_key', '80C.ppf')->sole(), 'verified', 150000, null, $this->approver);
    $change = "Employee code,Financial year,Investment item,Amount declared\n{$asha->employee_code},2026-27,80C.ppf,90000\n{$asha->employee_code},2026-27,80D.self_family,30000\n";
    $new = di4Stage('investments', $change, ['mode' => 'new']);
    expect($new->skip_count)->toBe(2)->and(di4Rows($new)[1]->match['reason'])->toContain('verified');
    di4Run(di4Stage('investments', $change, ['mode' => 'update']));
    expect($declaration->refresh()->amount('80D'))->toBe(30000.0)->and($declaration->amount('80C'))->toBe(150000.0)->and($declaration->status)->toBe('submitted');

    // A declaration payroll has verified is not changed by an upload.
    $declaration->update(['status' => 'verified', 'verified_by' => $this->approver->id, 'verified_at' => now()]);
    expect(di4Rows(di4Stage('investments', "Employee code,Financial year,Investment item,Amount declared\n{$asha->employee_code},2026-27,80E.education_loan_interest,1000\n"))[1]->errors[0])
        ->toContain('Payroll has verified');
});

it('declares rent for the HRA exemption with the landlord rules of the declaration screen', function () {
    $asha = di4Employee(1500000);
    $ravi = di4Employee(900000);
    di4Setting('payroll.tax.landlord_pan_required', true);
    $csv = "Employee code,Financial year,Rent per month,Months rented,Metro city (yes/no),City,Landlord name,Landlord PAN,Other landlords,Address of the rented home,Tax regime\n"
        ."{$asha->employee_code},2026-27,25000,12,yes,Mumbai,Ramesh Iyer,aaapi1234c,,12 Hill Road,old\n"
        ."{$ravi->employee_code},2026-27,14000,9,maybe,Pune,Sunita,,,,\n"
        ."{$ravi->employee_code},2026-27,14000,13,no,Pune,Sunita,BADPAN,,,\n";
    $import = di4Stage('hra_declarations', $csv);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(1)->and($import->error_count)->toBe(2)
        ->and(implode(' ', $rows[2]->errors))->toContain('Metro city must be yes or no')->toContain('PAN of every landlord')
        ->and(implode(' ', $rows[3]->errors))->toContain('1 to 12')->toContain('ABCDE1234F')->not->toContain('BADPAN');

    di4Run($import);
    $declaration = EmployeeTaxDeclaration::query()->where('employee_id', $asha->id)->sole();
    $rent = TdsEmployeeInvestment::query()->where('employee_id', $asha->id)->where('item_key', 'HRA_RENT.rent')->sole();
    expect($declaration->amount('HRA_RENT'))->toBe(300000.0)->and($declaration->amount('METRO'))->toBe(1.0)->and($declaration->regime)->toBe('old')
        ->and($rent->details)->toMatchArray(['monthly_rent' => 25000, 'months' => 12, 'landlord_name' => 'Ramesh Iyer', 'landlord_pan' => 'AAAPI1234C', 'city' => 'Mumbai']);

    // The same row again is skipped; a new rent replaces the old one on update, and the old regime's HRA exemption counts.
    expect(di4Stage('hra_declarations', $csv)->skip_count)->toBe(1);
    $update = "Employee code,Financial year,Rent per month,Metro city (yes/no),Landlord PAN\n{$asha->employee_code},2026-27,30000,no,AAAPI1234C\n";
    expect(di4Stage('hra_declarations', $update, ['mode' => 'new'])->skip_count)->toBe(1);
    di4Run(di4Stage('hra_declarations', $update, ['mode' => 'update']));
    expect($declaration->refresh()->amount('HRA_RENT'))->toBe(360000.0)->and($declaration->amount('METRO'))->toBe(0.0)
        ->and(($this->tax)($asha)->inputs['tax']['hra_exempt'])->toBeGreaterThan(0.0);
});

it('saves the previous employer of the year on the declaration (Workforce) and TDS counts the tax deducted there', function () {
    $asha = di4Employee(1500000);
    $before = ($this->tax)($asha)->inputs['tax'];
    $csv = "Employee code,Financial year,Previous employer,Their TAN,Worked there from,Worked there until,Taxable salary there,Income tax deducted there,PF deducted there,Professional tax deducted there,Gratuity exemption used there,Leave encashment exemption used there\n"
        ."{$asha->employee_code},2026-27,Acme Software,blra12345b,2026-04-01,2026-06-30,300000,12000,10800,600,,\n"
        ."{$asha->employee_code},2026-27,Acme Again,BAD,2025-04-01,2026-06-30,1,1,,,,\n";
    $import = di4Stage('previous_employment', $csv);
    expect($import->create_count)->toBe(1)->and($import->error_count)->toBe(1)
        ->and(implode(' ', di4Rows($import)[2]->errors))->toContain('ABCD12345E')->toContain('must fall in 2026-27');

    di4Run($import);
    $declaration = EmployeeTaxDeclaration::query()->where('employee_id', $asha->id)->sole();
    expect((float) $declaration->previous_employer_income)->toBe(300000.0)->and((float) $declaration->previous_employer_tds)->toBe(12000.0)
        ->and($declaration->previous_employer)->toMatchArray(['name' => 'Acme Software', 'tan' => 'BLRA12345B', 'from' => '2026-04-01', 'to' => '2026-06-30', 'pf' => 10800, 'pt' => 600])
        ->and(($this->tax)($asha)->inputs['tax']['already_deducted'])->toBe($before['already_deducted'] + 12000.0);

    // The same details again are skipped; new figures replace them on update only.
    expect(di4Stage('previous_employment', $csv)->skip_count)->toBe(1);
    $changed = str_replace('300000,12000', '320000,13000', $csv);
    expect(di4Stage('previous_employment', $changed, ['mode' => 'new'])->skip_count)->toBe(1);
    expect(di4Run(di4Stage('previous_employment', $changed, ['mode' => 'update']))->update_count)->toBe(1)
        ->and((float) $declaration->refresh()->previous_employer_tds)->toBe(13000.0);
});

it('changes statutory applicability and numbers through the statutory actions, never showing a number', function () {
    $asha = di4Employee();
    $ravi = employeeWithUser(null, ['task.view']);
    forceLifecycle($ravi, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $csv = "Employee code,PF applicable (yes/no),ESI applicable (yes/no),PT applicable (yes/no),PT state,Tax regime,PAN,Aadhaar reference,UAN,PF number,ESI number\n"
        ."{$asha->employee_code},yes,no,yes,mh,old,,,100200300400,KN/BNG/0012345/000/0000123,\n"
        ."{$ravi->employee_code},no,,,,,,,,,3100123456\n"
        ."{$ravi->employee_code},,,,,,,,987,,\n"
        ."{$asha->employee_code},,,,,,,,,,\n";
    $import = di4Stage('employee_statutory', $csv);
    $rows = di4Rows($import);
    expect($import->update_count)->toBe(1)->and($import->create_count)->toBe(1)->and($import->error_count)->toBe(2)
        ->and($rows[3]->errors[0])->toBe('UAN must be 12 digits.')
        ->and($rows[4]->errors[0])->toContain('at least one')
        ->and(json_encode($rows->pluck('match')->all()))->not->toContain('100200300400')->not->toContain('3100123456');

    $import = di4Run($import);
    $detail = EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $asha->id)->sole();
    $raviDetail = EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $ravi->id)->sole();
    expect($detail->uan)->toBe('100200300400')->and($detail->pf_number)->toBe('KN/BNG/0012345/000/0000123')->and($detail->esic_applicable)->toBeFalse()
        ->and($detail->pt_state_code)->toBe('MH')->and($detail->tax_regime)->toBe('old')
        ->and($raviDetail->esic_number)->toBe('3100123456')->and($raviDetail->pf_applicable)->toBeFalse()
        ->and(implode(' ', $import->rows()->pluck('result')->all()))->not->toContain('100200300400')->toContain('UAN');

    // The same values again are skipped; an employee with details on file is outside "new" only.
    expect(di4Stage('employee_statutory', $csv)->skip_count)->toBe(2);
    $changed = "Employee code,UAN\n{$asha->employee_code},100200300499\n";
    expect(di4Stage('employee_statutory', $changed, ['mode' => 'new'])->skip_count)->toBe(1)
        ->and(di4Run(di4Stage('employee_statutory', $changed, ['mode' => 'update']))->update_count)->toBe(1)
        ->and($detail->refresh()->uan)->toBe('100200300499');
});

it('turns component amounts into a draft compensation cycle that pays only after four different people act on it', function () {
    $actors = compensationActors();
    $joiner = employeeWithUser(null, ['task.view']);
    forceLifecycle($joiner, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $paid = di4Employee(600000);
    $csv = "Employee code,Salary structure code,Effective from,Annual CTC,CONV\n"
        ."{$joiner->employee_code},STANDARD,2026-11-01,\"6,00,000\",1600\n"
        ."{$paid->employee_code},standard,2026-11-01,720000,2400\n"
        ."{$paid->employee_code},STANDARD,2026-11-01,720000,\n"
        ."NOBODY,STANDARD,2026-11-01,500000,1600\n";
    $import = di4Stage('salary_components', $csv);
    $rows = di4Rows($import);
    expect($import->mapping)->toMatchArray(['CONV' => 'component.CONV'])
        ->and($import->create_count)->toBe(1)->and($import->update_count)->toBe(1)->and($import->error_count)->toBe(2)
        ->and(implode(' ', $rows[3]->errors))->toContain('at least one pay item')->toContain('row 2')
        ->and($rows[4]->errors[0])->toContain('does not exist');

    // New only: the employee with a CTC on file is skipped. The file holds pay: only its uploader sees it.
    expect(di4Stage('salary_components', $csv, ['mode' => 'new'])->skip_count)->toBe(1)
        ->and($this->approver->can('view', $import))->toBeFalse()->and($this->hr->can('view', $import))->toBeTrue();

    $import = di4Run($import);
    $cycle = CompensationCycle::query()->findOrFail($import->options['compensation_cycle_ids'][0]);
    $lines = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $cycle->id)->get()->keyBy('employee_id');
    expect($cycle->status)->toBe('draft')->and($cycle->prepared_by)->toBe($this->hr->id)
        ->and($lines[$joiner->id]->change_type)->toBe('hire')->and($lines[$paid->id]->change_type)->toBe('revision')
        ->and($lines[$paid->id]->component_values)->toEqual(['CONV' => 2400])
        ->and(EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $joiner->id)->exists())->toBeFalse();

    $cycles = app(CompensationCycles::class);
    $cycles->submit($cycle, $this->hr);
    $cycles->review($cycle->refresh(), $actors['reviewer']);
    $cycles->approve($cycle->refresh(), $actors['approver']);
    expect(fn () => $cycles->execute($cycle->refresh(), $this->hr))->toThrow(RuntimeException::class);
    $cycles->execute($cycle->refresh(), $actors['executor']);
    $assignment = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->where('employee_id', $paid->id)->whereDate('effective_from', '2026-11-01')->sole();
    expect((float) $assignment->ctc_annual)->toBe(720000.0)->and($assignment->component_values)->toEqual(['CONV' => 2400]);
});

it('moves employees to another pay group from a date, carrying the CTC in force, through a compensation cycle', function () {
    $actors = compensationActors();
    $sales = di4Structure('SALES');
    $asha = di4Employee(600000);
    $ravi = di4Employee(900000);
    $joiner = employeeWithUser(null, ['task.view']);
    forceLifecycle($joiner, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $csv = "Employee code,New salary structure code,Effective from,Annual CTC,Change type,Reason\n"
        ."{$asha->employee_code},SALES,2026-11-01,,,Moved to sales\n"
        ."{$ravi->employee_code},sales,2026-11-01,960000,transfer,Moved with a raise\n"
        ."{$joiner->employee_code},SALES,2026-11-01,,,\n"
        ."{$asha->employee_code},STANDARD,2026-11-01,,,\n"
        ."{$ravi->employee_code},NOPE,2026-11-01,,,\n";
    $import = di4Stage('pay_group_changes', $csv);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(3)
        ->and($rows[3]->errors[0])->toContain('no compensation in force')
        ->and(implode(' ', $rows[4]->errors))->toContain('already on structure STANDARD')
        ->and(implode(' ', $rows[5]->errors))->toContain("'NOPE' does not exist");

    $import = di4Run($import);
    $lines = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->whereIn('compensation_cycle_id', $import->options['compensation_cycle_ids'])->get()->keyBy('employee_id');
    expect($lines[$asha->id]->salary_structure_id)->toBe($sales->id)->and((float) $lines[$asha->id]->ctc_annual)->toBe(600000.0)
        ->and($lines[$asha->id]->component_values)->toEqual(['CONV' => 1600])->and($lines[$asha->id]->change_type)->toBe('revision')
        ->and((float) $lines[$ravi->id]->ctc_annual)->toBe(960000.0)->and($lines[$ravi->id]->change_type)->toBe('transfer')
        ->and(app(PayGroupChangeImports::class)->cycles($import))->toHaveCount(1);

    // A second file for the same people waits: their changes are still open.
    expect(di4Rows(di4Stage('pay_group_changes', "Employee code,New salary structure code,Effective from\n{$asha->employee_code},SALES,2026-12-01\n"))[1]->errors[0])->toContain('still open');
});
