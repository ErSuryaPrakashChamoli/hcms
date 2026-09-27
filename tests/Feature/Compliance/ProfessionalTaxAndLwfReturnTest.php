<?php

use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Models\LwfReturnEntry;
use App\Domain\Compliance\Models\ProfessionalTaxReturnEntry;
use App\Domain\Compliance\Models\ProfessionalTaxRuleVersion;
use App\Domain\Compliance\Models\StatutorySnapshot;
use App\Domain\Compliance\Services\Returns\LwfReturns;
use App\Domain\Compliance\Services\Returns\ProfessionalTaxReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Domain\Organisation\Models\Establishment;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'entity' => $this->entity, 'establishment' => $this->bengaluru] = complianceCompany();
    $this->pune = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'PUNE', 'name' => 'Pune', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    $registrations = app(StatutoryRegistrations::class);
    $registrations->register(['legal_entity_id' => $this->entity->id, 'establishment_id' => $this->pune->id, 'registration_type' => 'pt_registration_certificate', 'registration_number' => 'PTMH998877', 'effective_from' => '2020-01-01'], 'Pune PT');
    $registrations->register(['legal_entity_id' => $this->entity->id, 'establishment_id' => $this->pune->id, 'registration_type' => 'lwf_registration', 'registration_number' => 'LWFMH4455', 'effective_from' => '2020-01-01'], 'Pune LWF');
    foreach (['EPF', 'ESI', 'PT', 'LWF', 'TDS'] as $statute) {
        EstablishmentStatutoryProfile::query()->create(['establishment_id' => $this->pune->id, 'statute' => $statute, 'applicable' => true, 'effective_from' => '2020-01-01']);
    }
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->payrollApprover = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    ['generator' => $this->generator, 'approver' => $this->approver] = complianceUsers($this->tenant);
    $this->pt = app(ProfessionalTaxReturns::class);
    $this->lwf = app(LwfReturns::class);
    $this->returns = app(StatutoryReturns::class);

    $this->puneEmployee = statutoryEmployee(360000, $this->pune, '100200300410');       // gross 28,560 in Maharashtra
    $this->puneEmployee->person->update(['gender' => 'male']);
    $this->blrEmployee = statutoryEmployee(360000, $this->bengaluru, '100200300411');   // Karnataka
});

it('builds professional tax per establishment state, never from the company', function () {
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);

    $mh = $this->returns->validate($this->pt->generate($this->pune, 2026, 9, $this->generator), $this->generator);
    $entry = ProfessionalTaxReturnEntry::query()->where('statutory_return_id', $mh->id)->sole();
    expect($mh->state_code)->toBe('MH')
        ->and($entry->employee_id)->toBe($this->puneEmployee->id)
        ->and($entry->state_code)->toBe('MH')
        ->and($entry->state_source)->toBe('establishment')
        ->and((float) $entry->calc_pt_amount)->toBe(200.0)
        ->and($mh->status)->toBe('validated')
        ->and(collect($mh->validation)->pluck('code'))->toContain('filing_frequency_unverified', 'rule_unverified');

    $ka = $this->pt->generate($this->bengaluru, 2026, 9, $this->generator);
    expect(ProfessionalTaxReturnEntry::query()->where('statutory_return_id', $ka->id)->pluck('employee_id')->all())->toBe([$this->blrEmployee->id])
        ->and($ka->state_code)->toBe('KA');

    $approved = $this->returns->approve($mh, $this->approver);
    expect(StatutorySnapshot::query()->where('statutory_return_id', $approved->id)->count())->toBe(1)
        ->and(ProfessionalTaxRuleVersion::query()->where('state', 'MH')->exists())->toBeTrue()
        ->and(ProfessionalTaxRuleVersion::query()->pluck('code')->unique()->all())->toBe(['PT']);
});

it('blocks professional tax whose state came from the legacy company profile or lacks a registration', function () {
    $this->bengaluru->update(['state' => null]);          // payroll falls back to the legacy company profile (KA)
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);
    $this->bengaluru->update(['state' => 'KA']);

    $return = $this->returns->validate($this->pt->generate($this->bengaluru, 2026, 9, $this->generator), $this->generator);
    expect(collect($return->validation)->where('severity', 'blocking')->pluck('code'))->toContain('state_from_company_profile');

    $nowhere = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'NOST', 'name' => 'No state', 'effective_from' => '2020-01-01']);
    expect(fn () => $this->pt->generate($nowhere, 2026, 9, $this->generator))->toThrow(RuntimeException::class, 'no state');

    $mysuru = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'MYS', 'name' => 'Mysuru', 'state' => 'KA', 'effective_from' => '2020-01-01']);
    $return = $this->returns->validate($this->pt->generate($mysuru, 2026, 9, $this->generator), $this->generator);
    expect(collect($return->validation)->where('severity', 'blocking')->pluck('code')->all())->toContain('missing_registration');
});

it('builds labour welfare fund returns in the state\'s contribution months only', function () {
    finalizedPayroll($this->company, 2026, 6, $this->preparer, $this->payrollApprover);
    $june = $this->returns->validate($this->lwf->generate($this->pune, 2026, 6, $this->generator), $this->generator);
    $entry = LwfReturnEntry::query()->where('statutory_return_id', $june->id)->sole();

    expect($entry->employee_id)->toBe($this->puneEmployee->id)
        ->and((float) $entry->calc_ee_contribution)->toBe(25.0)
        ->and((float) $entry->calc_er_contribution)->toBe(75.0)
        ->and($june->reconciliation_status)->toBe('balanced')
        ->and($june->status)->toBe('validated');

    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);
    $september = $this->returns->validate($this->lwf->generate($this->pune, 2026, 9, $this->generator), $this->generator);
    expect($september->totals['entries'])->toBe(0)
        ->and(collect($september->validation)->pluck('code'))->toContain('no_members');

    // A state with no LWF rule version cannot produce a valid return.
    $jaipur = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'JPR', 'name' => 'Jaipur', 'state' => 'RJ', 'effective_from' => '2020-01-01']);
    $rj = $this->returns->validate($this->lwf->generate($jaipur, 2026, 9, $this->generator), $this->generator);
    expect(collect($rj->validation)->where('severity', 'blocking')->pluck('code'))->toContain('statutory_rule_missing', 'missing_registration');
});
