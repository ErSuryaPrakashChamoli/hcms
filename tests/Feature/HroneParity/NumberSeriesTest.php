<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\CodeSeries;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeCodeSeries;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\Exits;
use App\Domain\Letters\Services\Letters;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Services\Loans;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Filament\Resources\EmployeeCodeSeries\EmployeeCodeSeriesResource;
use App\Filament\Resources\NumberSeries\NumberSeriesResource;
use App\Filament\Resources\NumberSeries\Pages\ManageNumberSeries;
use App\Support\Numbering\NumberSequence;
use App\Support\Numbering\NumberSeries;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| HROne setup parity P9 (Forms & templates → Code series): loans, letters (all or per type), exit cases, alumni requests
| and tickets are numbered by tenant-shaped series, for the organisation or one company. Without a series every code
| type issues exactly the numbers it issued before. Employee code series can be limited to an employment type.
*/

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->india = Company::factory()->create(['name' => 'Markedge India']);
    $this->uk = Company::factory()->create(['name' => 'Markedge UK']);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->series = app(NumberSeries::class);
});

function numSeriesHire(Company $company, array $position = [], array $employee = []): Employee
{
    $hired = app(HireEmployeeAction::class)->handle(['first_name' => 'Num', 'last_name' => fake()->unique()->lastName()], ['joining_date' => '2026-01-05', ...$employee], ['company_id' => $company->id, ...$position]);

    return forceLifecycle($hired, LifecycleState::Active);
}

it('issues exactly today\'s numbers when no series is set up', function () {
    $employee = numSeriesHire($this->india);

    $loan = app(Loans::class)->request($employee, 'personal', 12000, 6, 'Medical');
    $second = app(Loans::class)->request(numSeriesHire($this->india), 'salary_advance', 5000, 2, 'Advance');
    $letter = app(Letters::class)->generate('employment_certificate', $employee, [], $this->hr);
    $case = app(Exits::class)->initiate($employee, 'resignation', 'Moving', '2026-10-15', null, 30, $this->hr);

    expect($loan->number)->toBe('LN-2026-00001')->and($second->number)->toBe('LN-2026-00002')
        ->and($letter->number)->toBe('LTR-2026-00001')
        ->and($case->number)->toBe('EXIT-2026-00001')
        ->and($this->series->preview('alumni_request'))->toBe('ALR-2026-00001')
        ->and($this->series->next('alumni_request'))->toBe('ALR-2026-00001');
});

it('continues after numbers already issued, and shares the Phase 12 ticket counter', function () {
    $employee = numSeriesHire($this->india);
    // A loan numbered before (or imported) is never issued again: the counter starts at the highest one used.
    Loan::query()->create(['employee_id' => $employee->id, 'number' => 'LN-2026-00041', 'loan_type' => 'personal', 'principal' => 1000, 'installments' => 1, 'first_due_month' => '2026-11', 'status' => 'closed', 'reason' => 'Old']);
    expect(app(Loans::class)->request($employee, 'personal', 12000, 6, 'Medical')->number)->toBe('LN-2026-00042');

    NumberSequence::query()->create(['sequence' => 'TKT-2026', 'last_value' => 17]);
    expect(app(ServiceDesk::class)->nextNumber('TKT'))->toBe('TKT-2026-00018')
        ->and($this->series->next('ticket'))->toBe('TKT-2026-00019')
        ->and(app(ServiceDesk::class)->nextNumber('GRV'))->toBe('GRV-2026-00001');
});

it('numbers by the organisation series, a company\'s own series first, and skips inactive ones', function () {
    CodeSeries::create(['key' => 'loan', 'prefix' => 'LOAN/{FY}/', 'digits' => 4, 'start_number' => 100]);
    CodeSeries::create(['key' => 'loan', 'company_id' => $this->uk->id, 'prefix' => 'UKL-{YY}{MM}-', 'digits' => 3]);
    $indian = numSeriesHire($this->india);
    $british = numSeriesHire($this->uk);

    expect(app(Loans::class)->request($indian, 'personal', 1000, 1, 'A')->number)->toBe('LOAN/2026-27/0100')
        ->and(app(Loans::class)->request(numSeriesHire($this->india), 'personal', 1000, 1, 'B')->number)->toBe('LOAN/2026-27/0101')
        ->and(app(Loans::class)->request($british, 'personal', 1000, 1, 'C')->number)->toBe('UKL-2610-001');

    CodeSeries::query()->where('company_id', $this->uk->id)->first()->update(['status' => 'inactive']);
    expect(app(Loans::class)->request(numSeriesHire($this->uk), 'personal', 1000, 1, 'D')->number)->toBe('LOAN/2026-27/0102');

    // A period token restarts numbering with the period.
    $this->travelTo('2027-04-02 10:00:00');
    expect($this->series->next('loan', $this->india->id))->toBe('LOAN/2027-28/0100');
});

it('numbers each letter type by its own series when it has one, else the letter series', function () {
    CodeSeries::create(['key' => 'letter.employment_certificate', 'prefix' => 'EC/{YYYY}/', 'digits' => 3]);
    CodeSeries::create(['key' => 'letter', 'company_id' => $this->india->id, 'prefix' => 'MKI-L-', 'digits' => 4]);
    $employee = numSeriesHire($this->india);
    $abroad = numSeriesHire($this->uk);
    $letters = app(Letters::class);

    expect($letters->generate('employment_certificate', $employee, [], $this->hr)->number)->toBe('EC/2026/001')
        ->and($letters->generate('confirmation', $employee, [], $this->hr)->number)->toBe('MKI-L-0001')
        ->and($letters->generate('confirmation', $abroad, [], $this->hr)->number)->toBe('LTR-2026-00001')
        ->and($this->series->configured('letter.employment_certificate'))->toBe('yes')
        ->and($this->series->configured('letter.confirmation'))->toBe('fallback')
        ->and($this->series->configured('letter'))->toBe('partial');
});

it('never hands out one number twice: same patterns share a counter, taken numbers are skipped', function () {
    $employee = numSeriesHire($this->india);
    expect($this->series->next('exit_case', $this->india->id))->toBe('EXIT-2026-00001');

    // An organisation series with the built-in pattern continues the same counter.
    CodeSeries::create(['key' => 'exit_case', 'prefix' => 'EXIT-{YYYY}-', 'digits' => 5]);
    ExitCase::query()->create(['number' => 'EXIT-2026-00002', 'employee_id' => $employee->id, 'type' => 'resignation', 'status' => 'withdrawn', 'initiated_on' => '2026-10-01', 'last_working_day' => '2026-10-31']);
    expect($this->series->next('exit_case', $this->india->id))->toBe('EXIT-2026-00003');

    // Deleting the series goes back to the built-in format on the same counter.
    CodeSeries::query()->where('key', 'exit_case')->delete();
    expect($this->series->next('exit_case'))->toBe('EXIT-2026-00004');
});

it('validates a series: known code type, organisation-only types, characters, length and one per place', function () {
    expect(fn () => CodeSeries::create(['key' => 'payslip', 'prefix' => 'P-']))->toThrow(ValidationException::class)
        ->and(fn () => CodeSeries::create(['key' => 'ticket', 'company_id' => $this->india->id, 'prefix' => 'T-']))->toThrow(ValidationException::class, 'whole organisation only')
        ->and(fn () => CodeSeries::create(['key' => 'loan', 'prefix' => 'LN {X}']))->toThrow(ValidationException::class, 'letters, digits')
        ->and(fn () => CodeSeries::create(['key' => 'loan', 'prefix' => 'ABCDEFGHIJ-{FY}-', 'suffix' => '-XYZXYZXYZ', 'digits' => 10]))->toThrow(ValidationException::class, '32 characters');

    CodeSeries::create(['key' => 'loan', 'company_id' => $this->india->id, 'prefix' => 'A-']);
    expect(fn () => CodeSeries::create(['key' => 'loan', 'company_id' => $this->india->id, 'prefix' => 'B-']))->toThrow(ValidationException::class, 'already has a series');

    $series = CodeSeries::query()->first();
    $series->withAuditReason('New format')->update(['prefix' => 'IN-LN-']);
    expect(AuditEvent::query()->where('entity_type', CodeSeries::class)->where('entity_id', (string) $series->id)->count())->toBeGreaterThanOrEqual(2);
});

it('reports which code types are set up, for every company of company-level types', function () {
    expect($this->series->configured('loan'))->toBe('default');
    CodeSeries::create(['key' => 'loan', 'company_id' => $this->india->id, 'prefix' => 'IN-']);
    expect($this->series->configured('loan'))->toBe('partial');
    CodeSeries::create(['key' => 'loan', 'company_id' => $this->uk->id, 'prefix' => 'UK-']);
    expect($this->series->configured('loan'))->toBe('yes');

    $overview = collect($this->series->overview())->keyBy('key');
    expect($overview['loan']['configured'])->toBe('yes')
        ->and($overview['ticket'])->toMatchArray(['module' => 'Helpdesk', 'applicable_on' => 'enterprise', 'configured' => 'default', 'next' => 'TKT-2026-00001'])
        ->and($overview->keys())->toContain('letter.appointment', 'exit_case', 'alumni_request');
});

it('manages series on the Number series screen with the guided Code type → Configure → Review steps', function () {
    $this->get(NumberSeriesResource::getUrl('index'))->assertOk()->assertSee('Number series');

    Livewire::test(ManageNumberSeries::class)
        ->callAction('create', ['key' => 'alumni_request', 'company_id' => NumberSeriesResource::ALL_COMPANIES, 'prefix' => 'ALUM-{YY}-', 'digits' => 4, 'start_number' => 1, 'status' => 'active'])
        ->assertHasNoActionErrors();
    expect(CodeSeries::query()->where('key', 'alumni_request')->value('prefix'))->toBe('ALUM-{YY}-')
        ->and($this->series->next('alumni_request'))->toBe('ALUM-26-0001');

    $page = Livewire::test(ManageNumberSeries::class)->assertCanSeeTableRecords(CodeSeries::query()->get())->assertSee('Configured?');
    expect(collect($page->instance()->codeTypes())->firstWhere('key', 'alumni_request')['configured_label'])->toBe('Yes');

    $viewer = tenantUser($this->tenant, ['employee.view']);
    $this->actingAs($viewer)->get(NumberSeriesResource::getUrl('index'))->assertForbidden();
});

it('gives employees of an employment type their own code series (company + type, company, type, default)', function () {
    $contract = EmploymentType::factory()->create(['name' => 'Contract']);
    $intern = EmploymentType::factory()->create(['name' => 'Intern']);
    EmployeeCodeSeries::create(['company_id' => $this->india->id, 'prefix' => 'IN-', 'digits' => 3]);
    EmployeeCodeSeries::create(['company_id' => $this->india->id, 'employment_type_id' => $contract->id, 'prefix' => 'INC-', 'digits' => 3]);
    EmployeeCodeSeries::create(['company_id' => null, 'employment_type_id' => $intern->id, 'prefix' => 'INT-', 'digits' => 3]);
    EmployeeCodeSeries::create(['company_id' => null, 'prefix' => 'GRP-', 'digits' => 3]);

    expect(numSeriesHire($this->india, ['employment_type_id' => $contract->id])->employee_code)->toBe('INC-001')
        ->and(numSeriesHire($this->india)->employee_code)->toBe('IN-001')
        ->and(numSeriesHire($this->india, ['employment_type_id' => $intern->id])->employee_code)->toBe('IN-002')
        ->and(numSeriesHire($this->uk, ['employment_type_id' => $intern->id])->employee_code)->toBe('INT-001')
        ->and(numSeriesHire($this->uk, ['employment_type_id' => $contract->id])->employee_code)->toBe('GRP-001')
        ->and(app(EmployeeCodeGenerator::class)->preview($this->india->id, $contract->id))->toBe('INC-002');

    $this->get(EmployeeCodeSeriesResource::getUrl('index'))->assertOk()->assertSee('Employment type');
});
