<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

/*
 | Acceptance: automatic location-based compliance. One company with establishments in Karnataka (central sphere for
 | the demonstration), Maharashtra, Tamil Nadu and Gujarat (a factory), and one office whose state is not configured.
 | Applicability is decided per establishment and per month from the configured state, type, appropriate government,
 | statutory profile and headcount; a rule that changes during the year (EPF wage ceiling on 17 Sep 2026; the central
 | register forms on 8 May 2026; a state threshold on 1 Oct 2025) applies from its own date; an employee transferred
 | between states is reported in each state for the months worked there; nothing is filled in from the head office.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-26 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'entity' => $this->entity, 'establishment' => $this->bengaluru] = complianceCompany();
    $this->bengaluru->update(['appropriate_government' => 'central', 'ss_appropriate_government' => 'central']);
    $make = fn (string $code, string $name, ?string $state, string $type) => Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => $code, 'name' => $name, 'state' => $state,
        'establishment_type' => $type, 'appropriate_government' => 'state', 'ss_appropriate_government' => 'state', 'effective_from' => '2020-01-01']);
    $this->pune = $make('PUNE', 'Pune', 'MH', 'office');
    $this->chennai = $make('CHE', 'Chennai', 'TN', 'shop');
    $this->ahmedabad = $make('AMD', 'Ahmedabad works', 'GJ', 'factory');
    $this->delhi = $make('DEL', 'Delhi office', null, 'office');
    $this->delhi->update(['appropriate_government' => null]);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->hr = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.generate']);
    $this->reports = app(ComplianceReports::class);

    // Pune has 12 employees: above Maharashtra's earlier threshold (10) and below the threshold of 20 in force from 1 Oct 2025.
    $this->puneStaff = collect(range(1, 11))->map(fn ($i) => statutoryEmployee(300000, $this->pune, '20030040050'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)));
    $this->transferred = statutoryEmployee(600000, $this->pune, '200300400599'); // gross above ₹25,000: PT in Maharashtra and in Karnataka
    $this->highEarner = statutoryEmployee(900000, $this->bengaluru, '100200300499'); // basic ₹30,000: above both EPF wage ceilings
    statutoryEmployee(300000, $this->chennai, '300400500601');
    statutoryEmployee(300000, $this->ahmedabad, '300400500602');
});

function planItem(array $plan, string $key, int $establishmentId): ?array
{
    return collect($plan)->first(fn ($i) => $i['definition']->key === $key && $i['target']['type'] === 'establishment' && $i['target']['id'] === $establishmentId);
}

it('decides each obligation from the establishment\'s own state, type and appropriate government — never from the head office', function () {
    $plan = $this->reports->plan($this->company, ReportPeriod::month(2026, 6));
    $keys = fn (Establishment $e) => collect($plan)->filter(fn ($i) => $i['target']['type'] === 'establishment' && $i['target']['id'] === $e->id)->map(fn ($i) => $i['definition']->key)->values();

    // Each state's own shops and establishments registers, for that state's establishments only.
    expect($keys($this->pune))->toContain('se_muster_wages:MH', 'se_leave_book:MH')->not->toContain('se_muster_wages:KA')
        ->and($keys($this->chennai))->toContain('se_persons_employed:TN', 'se_wages:TN')->not->toContain('se_muster_wages:MH')
        ->and($keys($this->bengaluru))->toContain('se_muster_wages:KA')
        // The office with no state gets no state's obligations: the head office's Karnataka rules are not lent to it.
        ->and($keys($this->delhi)->filter(fn ($k) => str_contains($k, ':')))->toBeEmpty()
        ->and(collect($this->reports->configurationGaps($this->company, ReportPeriod::month(2026, 6)))->where('target', 'Delhi office (DEL)')->pluck('missing')->all())
        ->toContain('State', 'Appropriate government (Code on Wages, OSH Code)');

    // A factory is not a shop or establishment: the Gujarat shops and establishments register does not apply, with the reason.
    $factory = planItem($plan, 'se_muster_wages:GJ', $this->ahmedabad->id);
    expect($factory['determination'])->toBe('NOT_APPLICABLE')
        ->and($factory['reason'])->toContain('this one is a Factory');

    // The appropriate government decides the register form: central rules for Bengaluru, the state's form for Pune,
    // and configuration required for Delhi, where it is not set.
    $central = planItem($plan, 'wage_register', $this->bengaluru->id);
    $state = planItem($plan, 'wage_register', $this->pune->id);
    $unset = planItem($plan, 'wage_register', $this->delhi->id);
    // Form IV rests on the Wages (Central) Rules, read on labour.gov.in (2026-10-27): plainly applicable, as is the
    // leave register (Form XX) on the signed OSH Rules copy. The state-sphere layout stays pending verification.
    $leave = planItem($plan, 'leave_register', $this->bengaluru->id);
    expect($central['resolved']->form)->toBe('Form IV (= OSH Form XV)')
        ->and($central['determination'])->toBe('APPLICABLE')
        ->and($central['legal'])->toBe('LEGAL_SOURCE_VERIFIED')
        ->and($central['effective'])->toBe('from 2026-05-08')
        ->and($central['sources'][0][1])->toStartWith('https://www.labour.gov.in/')
        ->and($leave['resolved']->form)->toBe('Form XX')
        ->and($leave['determination'])->toBe('APPLICABLE')
        ->and($leave['legal'])->toBe('LEGAL_SOURCE_VERIFIED')
        ->and($state['resolved']->form)->toStartWith('Register of wages — PeopleOS layout')
        ->and($state['determination'])->toBe('PENDING_VERIFICATION')
        ->and($unset['determination'])->toBe('MISSING_CONFIGURATION')
        ->and($unset['reason'])->toContain('appropriate government');

    // Tamil Nadu's professional tax is half-yearly (not due in a month's pack). Its rates are the Greater Chennai
    // Corporation's, read on the corporation's site, but other local bodies are not confirmed: pending verification.
    expect(collect($plan)->firstWhere('id', 'pt_statement:TN|state|TN'))->toBeNull();
    $tnPt = collect($this->reports->plan($this->company, ReportPeriod::fromKey('FY:2026:H1')))->firstWhere('id', 'pt_statement:TN|state|TN');
    expect($tnPt['determination'])->toBe('PENDING_VERIFICATION')
        ->and($tnPt['resolved']->legalReview)->toContain('other local bodies');

    // Tamil Nadu's shops and establishments wage register (Form W) rests on the official gazette, but its layout is
    // PeopleOS's: pending verification. Karnataka's muster-cum-wages register (Form T) rests on secondary sources only:
    // legally unverified.
    $tnWages = planItem($plan, 'se_wages:TN', $this->chennai->id);
    $kaMuster = planItem($plan, 'se_muster_wages:KA', $this->bengaluru->id);
    expect($tnWages['determination'])->toBe('PENDING_VERIFICATION')
        ->and($tnWages['legal'])->toBe('LEGAL_REVIEW_REQUIRED')
        ->and($tnWages['resolved']->legalReview)->toContain('PeopleOS register layout')
        ->and($kaMuster['determination'])->toBe('LEGALLY_UNVERIFIED')
        ->and($kaMuster['legal'])->toBe('UNVERIFIED');

    // Every determination keeps its reason (when it is not plainly applicable), legal basis and sources.
    foreach ($plan as $item) {
        expect($item['determination'])->toBeIn(array_keys(ComplianceReports::DETERMINATIONS))
            ->and($item['resolved']->legalBasis)->not->toBe('');
        if (in_array($item['determination'], ['NOT_APPLICABLE', 'MISSING_CONFIGURATION'], true)) {
            expect($item['reason'])->not->toBeEmpty();
        }
    }
});

it('applies a threshold, a form and a rule from their own dates within one reporting year', function () {
    // Maharashtra raised its shops and establishments threshold from 10 to 20 workers on 1 Oct 2025. In FY 2025-26 the
    // muster roll-cum-wages register is decided month by month: it applies to Pune's 12 workers until September and,
    // from October, may not apply (the new threshold is not primary-verified, so it is not dropped — it is marked).
    $year = collect($this->reports->plan($this->company, ReportPeriod::fromKey('FY:2025')))->firstWhere('id', 'se_muster_wages:MH|establishment|'.$this->pune->id);
    $months = collect($year['forms'])->keyBy('period');
    expect($months['M:2025-09']['applicability'])->toBe('APPLIES')
        ->and($months['M:2025-11']['applicability'])->toBe('PENDING_VERIFICATION')
        ->and($year['determination'])->toBe('PENDING_VERIFICATION')
        ->and($year['reason'])->toContain('Applies from 20 employees')->toContain('from 2025-10-01')->toContain('PeopleOS counts 12');

    // The central register forms changed on 8 May 2026: April 2026 is kept in the earlier consolidated form, June 2026
    // in Form IV — in the same calendar-year pack.
    $calendar = collect($this->reports->plan($this->company, ReportPeriod::fromKey('CY:2026')))->firstWhere('id', 'wage_register|establishment|'.$this->bengaluru->id);
    $forms = collect($calendar['forms'])->keyBy('period');
    expect($forms['M:2026-04']['form'])->toBe('Form B (wage register) and Form C (loans and recoveries)')
        ->and($forms['M:2026-06']['form'])->toBe('Form IV (= OSH Form XV)');

    // TDS statements: Form 24Q for the quarter to March 2026, Form No. 138 from April 2026.
    $quarters = collect($this->reports->plan($this->company, ReportPeriod::fromKey('CY:2026')))->filter(fn ($i) => str_starts_with($i['definition']->key, 'tds_quarterly'));
    expect($quarters->mapWithKeys(fn ($i) => [$i['definition']->key => array_map(fn ($p) => $p->key(), $i['periods'])])->all())
        ->toBe(['tds_quarterly' => ['FY:2026:Q1', 'FY:2026:Q2', 'FY:2026:Q3'], 'tds_quarterly_24q' => ['FY:2025:Q4']]);
});

it('reports an employee in each state for the months worked there, and figures under the rule version of each month', function () {
    app(EstablishmentAssignments::class)->assign($this->transferred, $this->bengaluru, '2026-07-01', 'Transferred to Bengaluru');
    foreach ([6, 7, 8, 10] as $month) {
        finalizedPayroll($this->company, 2026, $month, $this->preparer, $this->approver);
    }
    $this->actingAs($this->hr);
    $code = $this->transferred->employee_code;

    // Professional tax: Maharashtra for June (in Pune), Karnataka for July (in Bengaluru) — from the state on each line.
    $sheet = fn (string $item, ReportPeriod $period) => xlsxSheets($this->reports->single($this->company, $period, $item, $this->hr)['content']);
    $junMh = $sheet('pt_statement:MH|state|MH', ReportPeriod::month(2026, 6));
    $julMh = $sheet('pt_statement:MH|state|MH', ReportPeriod::month(2026, 7));
    $julKa = $sheet('pt_statement:KA|state|KA', ReportPeriod::month(2026, 7));
    expect(sheetHas($junMh['PT deducted'], $code))->toBeTrue()
        ->and(sheetHas($julMh['PT deducted'], $code))->toBeFalse()
        ->and(sheetHas($julKa['PT deducted'], $code))->toBeTrue();

    // The employee register follows the assignment: Pune in June, Bengaluru in July.
    $register = fn (Establishment $e, int $month) => firstSheet($this->reports->single($this->company, ReportPeriod::month(2026, $month), 'employee_register|establishment|'.$e->id, $this->hr)['content']);
    expect(sheetHas($register($this->pune, 6), $code))->toBeTrue()
        ->and(sheetHas($register($this->bengaluru, 7), $code))->toBeTrue()
        ->and(sheetHas($register($this->pune, 7), $code))->toBeFalse();

    // The EPF wage ceiling changed on 17 Sep 2026 (EPF v1 → v2): August's ECR uses ₹15,000, October's ₹25,000, and each
    // names the rule version it was calculated with.
    $pf = fn (int $month) => $sheet('pf_challan|establishment|'.$this->bengaluru->id, ReportPeriod::month(2026, $month))['ECR working'];
    $row = fn (array $rows) => collect($rows)->first(fn ($r) => in_array($this->highEarner->employee_code, $r, true));
    $heading = collect($pf(8))->first(fn ($r) => in_array('EPF wages', $r, true));
    $col = array_search('EPF wages', $heading, true);
    expect((float) $row($pf(8))[$col])->toBe(15000.0)
        ->and((float) $row($pf(10))[$col])->toBe(25000.0)
        ->and(sheetHas($pf(8), 'Calculated with: Employees Provident Fund v1 (draft)'))->toBeTrue()
        ->and(sheetHas($pf(10), 'Calculated with: Employees Provident Fund — wage ceiling ₹25,000 v2 (review)'))->toBeTrue();

    // A year pack records both versions.
    $pack = $this->reports->request($this->company, ReportPeriod::fromKey('FY:2026:Q3'), $this->hr);
    $versions = collect($pack->metadata['rules'])->where('code', 'EPF')->pluck('version')->sort()->values()->all();
    expect($pack->status)->toBe('completed')->and($versions)->toBe([2]);
    $q2 = $this->reports->request($this->company, ReportPeriod::fromKey('FY:2026:Q2'), $this->hr);
    expect(collect($q2->metadata['rules'])->where('code', 'EPF')->pluck('version')->sort()->values()->all())->toBe([1]);
});

it('never uses a proposed rule version in payroll, and uses it in reports only as provisional', function () {
    $resolver = app(ComplianceRules::class);
    expect($resolver->resolve('BONUS', '2026-03-31'))->toBeNull()
        ->and($resolver->resolve('LWF', '2026-06-30', 'HR')->version)->toBe(1)           // in force: v1
        ->and($resolver->resolveWithProposed('LWF', '2026-06-30', 'HR')['proposed']->version)->toBe(5) // prepared: v5 (₹35 cap)
        ->and($resolver->resolveWithProposed('PT', '2026-09-30', 'WB')['proposed'])->toBeNull()     // not before its date
        ->and($resolver->resolveWithProposed('PT', '2026-10-31', 'WB')['proposed']->version)->toBe(2)
        ->and(ComplianceRule::query()->where('status', ComplianceRule::PROPOSED)->where('verification_status', ComplianceRule::VERIFIED)->exists())->toBeFalse();

    // Payroll for a Haryana establishment still deducts under the version in force (v1, flat), never the proposed v5.
    $gurugram = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'GGN', 'name' => 'Gurugram', 'state' => 'HR', 'establishment_type' => 'office', 'appropriate_government' => 'state', 'effective_from' => '2020-01-01']);
    foreach (['EPF', 'ESI', 'PT', 'LWF', 'TDS'] as $statute) {
        EstablishmentStatutoryProfile::query()->create(['establishment_id' => $gurugram->id, 'statute' => $statute, 'applicable' => true, 'effective_from' => '2020-01-01']);
    }
    $haryana = statutoryEmployee(600000, $gurugram, '400500600701');
    $run = finalizedPayroll($this->company, 2026, 6, $this->preparer, $this->approver);
    $lwf = $run->entries()->where('employee_id', $haryana->id)->first()->lines()->where('code', 'LWF_EE')->first();
    expect((float) $lwf->amount)->toBe(31.0)->and($lwf->basis['rule_version'])->toBe(1);
});
