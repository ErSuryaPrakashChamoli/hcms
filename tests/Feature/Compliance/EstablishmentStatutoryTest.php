<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Models\ProfessionalTaxProfile;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = payrollCompany();
    $this->entity = LegalEntity::query()->where('company_id', $this->company->id)->sole();
    $this->ka = Establishment::query()->where('company_id', $this->company->id)->sole();
    $this->ka->update(['state' => 'KA', 'effective_from' => '2020-01-01']);
    $this->mh = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'PUNE', 'name' => 'Pune', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    $this->registrations = app(StatutoryRegistrations::class);
    $this->assignments = app(EstablishmentAssignments::class);
    $this->calc = fn ($employee, int $year = 2026, int $month = 9) => app(PayrollCalculator::class)->calculate($employee, PayrollPeriod::for($this->company, $year, $month));
});

it('stores registration numbers encrypted, hashed and masked, and audits them masked', function () {
    $registration = $this->registrations->register(['legal_entity_id' => $this->entity->id, 'establishment_id' => $this->mh->id, 'registration_type' => 'epf_establishment_code', 'registration_number' => ' mhpun 0012345 000 ', 'effective_from' => '2020-01-01'], 'Onboarding the Pune plant');

    $raw = DB::table('statutory_registrations')->where('id', $registration->id)->first();
    expect($registration->registration_number)->toBe('MHPUN0012345000')
        ->and($raw->registration_number)->not->toContain('MHPUN')
        ->and($raw->registration_number_last4)->toBe('5000')
        ->and($registration->maskedNumber())->toBe('••••5000')
        ->and($registration->statutory_authority)->toBe('EPFO')
        ->and($registration->state_code)->toBe('MH')
        ->and($registration->company_id)->toBe($this->company->id)
        ->and($registration->toArray())->not->toHaveKey('registration_number');

    $event = AuditEvent::query()->where('entity_type', StatutoryRegistration::class)->where('action', 'STATUTORY_REGISTRATION_CREATED')->sole();
    $logged = AuditEventChange::query()->where('audit_event_id', $event->id)->where('field', 'registration_number')->sole();
    expect($logged->after)->not->toContain('MHPUN')->and($event->reason)->toBe('Onboarding the Pune plant');
});

it('enforces registration levels, uniqueness, immutable identity, supersession and verifier separation', function () {
    expect(fn () => $this->registrations->register(['legal_entity_id' => $this->entity->id, 'registration_type' => 'esic_employer_code', 'registration_number' => '31000123450001001', 'effective_from' => '2020-01-01'], 'x'))
        ->toThrow(RuntimeException::class, 'belongs to an establishment');
    expect(fn () => $this->registrations->register(['legal_entity_id' => $this->entity->id, 'establishment_id' => $this->ka->id, 'registration_type' => 'gst', 'registration_number' => '1', 'effective_from' => '2020-01-01'], 'x'))
        ->toThrow(RuntimeException::class, 'Unknown statutory registration type');

    $tan = $this->registrations->register(['legal_entity_id' => $this->entity->id, 'registration_type' => 'tan', 'registration_number' => 'BLRA12345B', 'jurisdiction' => 'AO BLR-W-12', 'effective_from' => '2020-01-01'], 'Entity TAN');
    expect($tan->establishment_id)->toBeNull();

    $esic = $this->registrations->register(['legal_entity_id' => $this->entity->id, 'establishment_id' => $this->ka->id, 'registration_type' => 'esic_employer_code', 'registration_number' => '53000123450001001', 'effective_from' => '2020-01-01'], 'ESIC code');
    expect(fn () => $this->registrations->register(['legal_entity_id' => $this->entity->id, 'establishment_id' => $this->mh->id, 'registration_type' => 'esic_employer_code', 'registration_number' => '53000123450001001', 'effective_from' => '2020-01-01'], 'dup'))
        ->toThrow(RuntimeException::class, 'already active');
    expect(fn () => $this->registrations->update($esic, ['registration_number' => '1'], 'typo'))->toThrow(RuntimeException::class, 'supersede');

    $new = $this->registrations->supersede($esic, ['registration_number' => '53000999990001001'], '2026-10-01', 'Re-registered after relocation');
    expect($esic->fresh()->status)->toBe('superseded')
        ->and($esic->fresh()->effective_to->toDateString())->toBe('2026-09-30')
        ->and($this->registrations->forEstablishment($this->ka, 'esic_employer_code', '2026-09-15')->id)->toBe($esic->id)
        ->and($this->registrations->forEstablishment($this->ka, 'esic_employer_code', '2026-10-15')->id)->toBe($new->id)
        ->and($this->registrations->forEstablishment($this->mh, 'tan', '2026-10-15')->id)->toBe($tan->id);

    expect(fn () => $this->registrations->verify($new, $this->admin, 'ESIC portal'))->toThrow(RuntimeException::class, 'recorded it');
    $checker = tenantUser($this->tenant, ['compliance.registrations.manage']);
    $this->registrations->verify($new, $checker, 'ESIC registration certificate dated 01-10-2026');
    expect($new->fresh()->verification_status)->toBe('verified')->and($new->fresh()->verified_by)->toBe($checker->id);
});

it('keeps establishment profiles rate-free, non-overlapping and within the legal entity', function () {
    $profile = fn (array $a) => (new EstablishmentStatutoryProfile($a + ['establishment_id' => $this->mh->id, 'statute' => 'EPF', 'applicable' => true, 'effective_from' => '2020-01-01']))->save();

    expect(fn () => $profile(['settings' => ['employee_rate' => 0.12]]))->toThrow(RuntimeException::class, 'never carry rates');
    expect(fn () => $profile(['settings' => ['restrict_to_ceiling' => 15000]]))->toThrow(RuntimeException::class, 'yes/no');

    $profile(['settings' => ['restrict_to_ceiling' => false]]);
    expect(fn () => $profile(['effective_from' => '2026-01-01']))->toThrow(RuntimeException::class, 'overlapping');

    $other = LegalEntity::query()->create(['company_id' => $this->company->id, 'code' => 'OTHER', 'legal_name' => 'Other LLP', 'country' => 'IN', 'effective_from' => '2020-01-01']);
    $foreign = $this->registrations->register(['legal_entity_id' => $other->id, 'registration_type' => 'tan', 'registration_number' => 'MUMO11111C', 'effective_from' => '2020-01-01'], 'x');
    expect(fn () => $profile(['statute' => 'TDS', 'statutory_registration_id' => $foreign->id]))->toThrow(RuntimeException::class, 'same legal entity');

    $pt = ProfessionalTaxProfile::query()->create(['establishment_id' => $this->mh->id, 'applicable' => true, 'effective_from' => '2020-01-01']);
    expect($pt->statute)->toBe('PT')->and(ProfessionalTaxProfile::query()->count())->toBe(1);
});

it('keeps establishment assignment history immutable and one assignment per date', function () {
    $employee = salariedEmployee(240000);
    $first = $this->assignments->assign($employee, $this->ka, '2025-01-01', 'Joined in Bengaluru');
    $second = $this->assignments->assign($employee, $this->mh, '2026-09-01', 'Transferred to Pune', 'transfer');

    expect($first->fresh()->effective_to->toDateString())->toBe('2026-08-31')
        ->and($this->assignments->resolve($employee, '2026-08-31')['establishment']->id)->toBe($this->ka->id)
        ->and($this->assignments->resolve($employee, '2026-09-30')['establishment']->id)->toBe($this->mh->id)
        ->and($this->assignments->resolve($employee, '2026-09-30')['source'])->toBe('assignment')
        ->and(AuditEvent::query()->where('action', 'ESTABLISHMENT_ASSIGNED')->count())->toBe(2);

    expect(fn () => $this->assignments->assign($employee, $this->ka, '2026-06-01', 'Backdated'))->toThrow(RuntimeException::class, 'cannot be rewritten');
    expect(fn () => $second->update(['establishment_id' => $this->ka->id]))->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => $first->update(['effective_to' => '2026-07-31']))->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => $second->delete())->toThrow(RuntimeException::class, 'never deleted');
    expect(fn () => $this->assignments->assign($employee, $this->mh, '2026-10-01', ''))->toThrow(RuntimeException::class, 'reason');

    $otherCompany = Company::factory()->create();
    $foreign = Establishment::query()->where('company_id', $otherCompany->id)->sole();
    expect(fn () => $this->assignments->assign($employee, $foreign, '2026-11-01', 'Wrong company'))->toThrow(RuntimeException::class, 'different company');
});

it('takes professional tax and applicability from the employee\'s establishment on the period end date', function () {
    $employee = salariedEmployee(240000); // gross 19,040: Karnataka exempt below 25,000, Maharashtra 200
    $employee->person->update(['gender' => 'male']);

    // Legacy behaviour until the employee is assigned: company profile state (KA).
    expect(($this->calc)($employee)->amount('PT'))->toBe(0.0);

    $this->assignments->assign($employee, $this->ka, '2025-01-01', 'Joined');
    $this->assignments->assign($employee, $this->mh, '2026-09-01', 'Transfer', 'transfer');

    $august = ($this->calc)($employee, 2026, 8);
    $september = ($this->calc)($employee, 2026, 9);
    expect($august->amount('PT'))->toBe(0.0)
        ->and($september->amount('PT'))->toBe(200.0)
        ->and($september->lines[array_search('PT', array_column($september->lines, 'code'))]['basis']['state_source'])->toBe('establishment')
        ->and($september->inputs['statutory_context']['establishment_id'])->toBe($this->mh->id)
        ->and($september->inputs['statutory_context']['profile_source'])->toBe('legacy_company_profile');

    // Once the establishment has profiles, they decide applicability (PT off here), not the company.
    ProfessionalTaxProfile::query()->create(['establishment_id' => $this->mh->id, 'applicable' => false, 'effective_from' => '2020-01-01', 'reason' => 'Exempt unit']);
    EstablishmentStatutoryProfile::query()->create(['establishment_id' => $this->mh->id, 'statute' => 'ESI', 'applicable' => true, 'effective_from' => '2020-01-01']);
    $profiled = ($this->calc)($employee, 2026, 9);
    expect($profiled->has('PT'))->toBeFalse()
        ->and($profiled->has('ESI_EE'))->toBeTrue()
        ->and($profiled->has('PF_EE'))->toBeFalse() // no EPF profile on this establishment
        ->and($profiled->inputs['statutory_context']['profile_source'])->toBe('establishment_profile');
});

it('records the legal entity and establishment on each payroll entry', function () {
    $employee = salariedEmployee(600000);
    $this->assignments->assign($employee, $this->mh, '2025-01-01', 'Pune');
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open($this->company, 2026, 9, $this->admin), $this->admin);

    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->sole();
    expect($entry->establishment_id)->toBe($this->mh->id)
        ->and($entry->legal_entity_id)->toBe($this->entity->id)
        ->and($run->calculation_version)->toBe(PayrollCalculator::VERSION);
});

it('backfills registrations, profiles and assignments from the legacy company profile, idempotently', function () {
    CompanyStatutoryProfile::query()->where('company_id', $this->company->id)->update(['pf_establishment_code' => 'KABNG0000001000', 'tan' => 'BLRD00000A', 'esi_code' => '53000111110001001', 'lwf_applicable' => true, 'lwf_state' => 'MH']);
    $employee = salariedEmployee(600000);

    $this->artisan('peopleos:legal-entities:backfill', ['--tenant' => $this->tenant->slug])->assertSuccessful();
    $this->artisan('peopleos:legal-entities:backfill', ['--tenant' => $this->tenant->slug])->assertSuccessful();

    expect(StatutoryRegistration::query()->pluck('registration_type')->sort()->values()->all())->toBe(['epf_establishment_code', 'esic_employer_code', 'tan'])
        ->and(EstablishmentStatutoryProfile::query()->where('establishment_id', $this->ka->id)->count())->toBe(5)
        ->and(EstablishmentStatutoryProfile::query()->where('establishment_id', $this->ka->id)->where('statute', 'LWF')->value('applicable'))->toBeFalse()
        ->and(EmployeeEstablishmentAssignment::query()->where('employee_id', $employee->id)->where('source', 'backfill')->count())->toBe(1);
});
