<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Payroll\Inputs\BonusInput;
use App\Domain\Payroll\Inputs\PayrollInputs;
use App\Domain\Payroll\Models\BonusRun;
use App\Domain\Payroll\Models\BonusRunLine;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Services\BonusRuns;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Support\BonusBasis;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\BonusProcessing;
use App\Filament\Pages\PayrollDesk;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Compliance/ComplianceTestHelpers.php';

/*
| HROne parity PO-22 (matrix 25 §25.9.3 #1–#4, #7): the bonus run per accounting year. Statutory figures only from a
| VERIFIED BONUS rule version (the shipped versions are proposed: refused); with a verified fixture: eligibility (wages
| and minimum days), the calculation ceiling and its minimum-wage limb (pay-rate override with a reason), the employer's
| percentage within the rule's range, the minimum amount, ex gratia classified apart, second-person approval, BonusInput
| paying once and settling on finalization, and unprocessing lines not yet paid. CTC 2,40,000 on the STANDARD structure is
| basic 8,000 a month (above the 7,000 ceiling of the fixture), CTC 1,80,000 basic 6,000, CTC 9,00,000 basic 30,000.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'compensation.view', 'audit.view']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    ['company' => $this->company, 'establishment' => $this->establishment] = complianceCompany();
    $this->runs = app(BonusRuns::class);
    $this->payroll = fn (int $year, int $month) => finalizedPayroll($this->company, $year, $month, tenantUser($this->tenant, ['payroll.*', 'employee.*']), tenantUser($this->tenant, ['payroll.*', 'employee.*']));
    $this->line = fn (Employee $e): BonusRunLine => BonusRunLine::query()->where('employee_id', $e->id)->sole();
});

/** Test fixture: the Code on Wages version stood up as active and verified (in the product only RuleVerifications does this). */
function po22VerifyBonusRule(): void
{
    DB::table('compliance_rules')->where('code', 'BONUS')->where('version', 2)->update(['status' => ComplianceRule::ACTIVE, 'verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();
}

/** Test fixture: a verified version for FY 2026-27 whose calculation ceiling is fixed (no minimum-wage limb). */
function po22FixedCeilingRule(array $parameters = []): ComplianceRule
{
    $rule = ComplianceRule::query()->create(['jurisdiction' => 'IN', 'code' => 'BONUS', 'state' => null, 'name' => 'Statutory bonus — test fixture', 'version' => 9, 'effective_from' => '2026-04-01',
        'parameters' => $parameters + ['eligibility_ceiling' => 21000, 'calculation_ceiling' => 7000, 'calculation_ceiling_rule' => 'fixed', 'minimum_rate' => 1 / 12, 'minimum_amount' => 100, 'maximum_rate' => 0.2, 'minimum_days' => 30, 'payment_within_months' => 8],
        'status' => ComplianceRule::ACTIVE, 'verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();

    return $rule;
}

it('refuses to calculate while only a proposed BONUS version applies, and shows the year as awaiting verification', function () {
    statutoryEmployee(240000, $this->establishment, '100200300601');
    ($this->payroll)(2026, 9);

    $state = $this->runs->ruleState('2026-27');
    expect($state['ready'])->toBeFalse()
        ->and($state['problems'][0])->toBe('Statutory bonus — Code on Wages, 2019 (central sphere) v2 is proposed and awaits verification (the whole year).')
        ->and(fn () => $this->runs->prepare($this->company, '2026-27', $this->hr))->toThrow(RuntimeException::class, 'Awaiting verification of the BONUS rule')
        ->and(BonusRun::query()->count())->toBe(0)
        // The shipped rule data is untouched: both versions stay proposed.
        ->and(ComplianceRule::query()->where('code', 'BONUS')->pluck('status')->unique()->all())->toBe([ComplianceRule::PROPOSED]);

    // An active version that is not verified is refused too, whatever the enforcement switch says (it is off in tests).
    DB::table('compliance_rules')->where('code', 'BONUS')->where('version', 2)->update(['status' => ComplianceRule::ACTIVE, 'verification_status' => ComplianceRule::REVIEW]);
    app(ComplianceRules::class)->forget();
    expect(ComplianceRules::enforced())->toBeFalse()
        ->and(fn () => $this->runs->prepare($this->company, '2026-27', $this->hr))->toThrow(RuntimeException::class, 'v2 is review, not verified against an official source');

    // FY 2025-26 straddles the Payment of Bonus Act (v1, proposed) and the Code (v2): each month is named.
    expect($this->runs->ruleState('2025-26')['problems'][0])->toContain('Payment of Bonus Act, 1965 v1 is proposed and awaits verification (Apr 2025, May 2025');

    $this->get(BonusProcessing::getUrl(['year' => '2026-27']))->assertOk()->assertSee('Awaiting verification of the BONUS rule')
        ->assertSee('v2 is review, not verified against an official source');
    Livewire::withQueryParams(['year' => '2026-27'])->test(BonusProcessing::class)
        ->callAction('prepareBonus', ['company' => (string) $this->company->id, 'year' => '2026-27'])->assertNotified('Not done');
    expect(BonusRun::query()->count())->toBe(0);
});

it('decides eligibility on wages and minimum days and computes the bonus from the verified rule', function () {
    po22VerifyBonusRule();
    $standard = statutoryEmployee(240000, $this->establishment, '100200300611');
    $low = statutoryEmployee(180000, $this->establishment, '100200300612');
    $high = statutoryEmployee(900000, $this->establishment, '100200300613');
    $crossing = statutoryEmployee(240000, $this->establishment, '100200300614');
    compensate($crossing, 900000, '2026-09-01', ['CONV' => 1600], 'revision', 'Promotion');
    $newcomer = statutoryEmployee(240000, $this->establishment, '100200300615');
    forceLifecycle($newcomer, LifecycleState::Active, ['joining_date' => '2026-09-20']);
    foreach ([7, 8, 9] as $month) {
        ($this->payroll)(2026, $month);
    }

    $run = $this->runs->prepare($this->company, '2026-27', $this->hr);
    expect($run->rate_source)->toBe('rule_minimum')->and((float) $run->bonus_rate)->toBe(round(1 / 12, 6))
        ->and($run->payment_deadline->toDateString())->toBe('2027-11-30') // 31 Mar 2027 + payment_within_months (8)
        ->and($run->rules)->toHaveKey('2026-04')->and($run->rules['2027-03']['version'])->toBe(2);

    // Basic 8,000 a month is above the 7,000 calculation ceiling: 3 × 7,000 = 21,000 × 8⅓% = 1,750. The Code raises the
    // ceiling to the minimum wage if higher (not held), so the line is eligible and says so.
    $s = ($this->line)($standard);
    expect($s->eligibility)->toBe('eligible')->and($s->included)->toBeTrue()
        ->and((float) $s->bonus_days)->toBe(92.0)->and((float) $s->wages)->toBe(24000.0)
        ->and((float) $s->pay_rate)->toBe(7000.0)->and((float) $s->bonus_wages)->toBe(21000.0)
        ->and((float) $s->statutory_bonus)->toBe(1750.0)->and((float) $s->bonus_amount)->toBe(1750.0)
        ->and($s->reason)->toContain('minimum wage if higher')->toContain('Change the pay rate')
        ->and($s->from_month)->toBe('2026-07')->and($s->to_month)->toBe('2026-09')->and($s->pay_group)->toBe('STANDARD')
        ->and($s->basis['minimum'])->toEqual(1750.0)->and($s->basis['maximum'])->toEqual(4200.0);

    // Below the ceiling, the wages themselves: 3 × 6,000 = 18,000 → 1,500.
    expect((float) ($this->line)($low)->bonus_wages)->toBe(18000.0)->and((float) ($this->line)($low)->statutory_bonus)->toBe(1500.0);

    // Above the eligibility ceiling every month: not eligible, nothing to pay.
    $h = ($this->line)($high);
    expect($h->eligibility)->toBe('ineligible')->and($h->included)->toBeFalse()->and($h->reason)->toContain('above ₹21,000 a month throughout')
        ->and($h->bonus_wages)->toBeNull()->and((float) $h->bonus_amount)->toBe(0.0);

    // Crossed the eligibility ceiling during the year: HR decides (left out until included with a reason).
    $c = ($this->line)($crossing);
    expect($c->eligibility)->toBe('review')->and($c->included)->toBeFalse()->and((float) $c->bonus_amount)->toBe(0.0)
        ->and((float) $c->statutory_bonus)->toBe(1750.0);
    $this->runs->setIncluded($c, true, 'Eligible for July and August', $this->hr);
    expect((float) $c->refresh()->bonus_amount)->toBe(1750.0)->and($c->include_reason)->toBe('Eligible for July and August');

    // Fewer than the rule's minimum days (11 days paid in September): not eligible, and cannot be included.
    $n = ($this->line)($newcomer);
    expect($n->eligibility)->toBe('ineligible')->and($n->reason)->toBe("Paid for 11 days; at least 30 days' work in the year are needed.")
        ->and(fn () => $this->runs->setIncluded($n, true, 'Please', $this->hr))->toThrow(RuntimeException::class, 'not eligible for statutory bonus');

    // The employer's percentage: within the rule's range only; 8.33 stands for 8⅓%, 20 is the maximum.
    $settings = app(SettingsRepository::class);
    $settings->set('payroll.bonus.percent', 25, 'Generous year');
    expect(fn () => $this->runs->prepare($this->company, '2026-27', $this->hr))->toThrow(RuntimeException::class, "The bonus percentage 25% (Setup centre → Payroll) is outside the BONUS rule's range of 8.33% to 20%.");
    $settings->set('payroll.bonus.percent', 5, 'Lean year');
    expect(fn () => $this->runs->prepare($this->company, '2026-27', $this->hr))->toThrow(RuntimeException::class, 'outside the BONUS rule');
    $settings->set('payroll.bonus.percent', 8.33, 'Statutory minimum');
    expect((float) $this->runs->prepare($this->company, '2026-27', $this->hr)->bonus_rate)->toBe(round(1 / 12, 6))
        ->and((float) ($this->line)($standard)->statutory_bonus)->toBe(1750.0);
    $settings->set('payroll.bonus.percent', 20, 'Good year');
    $run = $this->runs->prepare($this->company, '2026-27', $this->hr);
    expect($run->rate_source)->toBe('setting')->and((float) ($this->line)($standard)->statutory_bonus)->toBe(4200.0)
        // HR's decision on the crossing employee survives the recalculation.
        ->and(($this->line)($crossing)->included)->toBeTrue()->and((float) ($this->line)($crossing)->bonus_amount)->toBe(4200.0);

    // The minimum amount is the rule's: a version whose minimum is 4,000 lifts 20% of 18,000 (3,600), not 4,200.
    po22FixedCeilingRule(['minimum_amount' => 4000]);
    $this->runs->prepare($this->company, '2026-27', $this->hr);
    expect((float) ($this->line)($low)->statutory_bonus)->toBe(4000.0)->and((float) ($this->line)($standard)->statutory_bonus)->toBe(4200.0)
        ->and(($this->line)($standard)->basis['rule'])->toBe('Statutory bonus — test fixture v9');
});

it('takes the bonus calculation out of the register into one shared basis', function () {
    po22VerifyBonusRule();
    $rule = ComplianceRule::query()->where('code', 'BONUS')->where('version', 2)->sole();
    $row = BonusBasis::row(null);
    $row['months'] = 3;
    $row['days'] = 92.0;
    $row['wages'] = 24000.0;
    $row['under_ceiling'] = 3;
    expect(BonusBasis::determine($row, $rule)[2])->toBe(BonusBasis::MINIMUM_WAGE)
        ->and(BonusBasis::minimum(21000, $rule))->toBe(1750.0)->and(BonusBasis::maximum(21000, $rule))->toBe(4200.0)
        ->and(BonusBasis::bonusWagesAt([['wages' => 8000.0, 'full_month' => 8000.0, 'ceiling' => 7000.0], ['wages' => 4000.0, 'full_month' => 8000.0, 'ceiling' => 7000.0]]))->toBe(10500.0)
        ->and(BonusBasis::bonusWagesAt([['wages' => 8000.0, 'full_month' => 8000.0, 'ceiling' => 7000.0]], 7500.0))->toBe(7500.0);
    $register = file_get_contents(app_path('Domain/ComplianceReports/Builders/BonusRegister.php'));
    expect($register)->toContain('BonusBasis::addMonth(')->toContain('BonusBasis::determine(')->not->toContain('function determine(');
});

it('changes the pay rate only for the minimum-wage limb, never below the ceiling, with a reason in the audit trail', function () {
    po22VerifyBonusRule();
    $standard = statutoryEmployee(240000, $this->establishment, '100200300621');
    foreach ([7, 8, 9] as $month) {
        ($this->payroll)(2026, $month);
    }
    $this->runs->prepare($this->company, '2026-27', $this->hr);
    $line = ($this->line)($standard);

    expect(fn () => $this->runs->overridePayRate($line, 6500, 'Lower', $this->hr))->toThrow(RuntimeException::class, 'cannot be below the calculation ceiling of ₹7,000')
        ->and(fn () => $this->runs->overridePayRate($line, 7500, ' ', $this->hr))->toThrow(RuntimeException::class, 'Give a reason');

    $this->runs->overridePayRate($line, 7500, 'Minimum wage for the scheduled employment is ₹7,500 a month', $this->hr);
    $line->refresh();
    expect((float) $line->pay_rate)->toBe(7500.0)->and((float) $line->bonus_wages)->toBe(22500.0)->and((float) $line->statutory_bonus)->toBe(1875.0)
        ->and((int) $line->edited_by)->toBe($this->hr->id);
    $audit = AuditEvent::query()->withoutTenancy()->where('entity_type', BonusRunLine::class)->where('entity_id', $line->id)->latest('id')->first();
    expect($audit->reason)->toBe('Minimum wage for the scheduled employment is ₹7,500 a month');

    // The override survives a recalculation; clearing it goes back to the ceiling.
    $this->runs->prepare($this->company, '2026-27', $this->hr);
    expect((float) $line->refresh()->statutory_bonus)->toBe(1875.0);
    $this->runs->overridePayRate($line, null, 'Minimum wage revised below the ceiling', $this->hr);
    expect((float) $line->refresh()->statutory_bonus)->toBe(1750.0)->and($line->override_reason)->toBeNull();

    // A version with a fixed calculation ceiling decides eligibility outright and offers no pay-rate change.
    po22FixedCeilingRule();
    $this->runs->prepare($this->company, '2026-27', $this->hr);
    expect($line->refresh()->eligibility)->toBe('eligible')->and($line->reason)->toBe("Wages at or below ₹21,000 a month and at least 30 days' work.")
        ->and(fn () => $this->runs->overridePayRate($line, 7500, 'Minimum wage', $this->hr))->toThrow(RuntimeException::class, 'fixes the calculation ceiling: pay anything above the statutory bonus as ex gratia');
});

it('sends bonus and ex gratia for a second person\'s approval, never one\'s own', function () {
    po22VerifyBonusRule();
    $standard = statutoryEmployee(240000, $this->establishment, '100200300631');
    $high = statutoryEmployee(900000, $this->establishment, '100200300632');
    $own = salariedEmployee(240000, ['payroll.*', 'employee.*']);
    foreach ([7, 8, 9] as $month) {
        ($this->payroll)(2026, $month);
    }
    $run = $this->runs->prepare($this->company, '2026-27', $this->hr);

    // Ex gratia: any employee, an amount HR enters with a reason, apart from statutory bonus.
    expect(fn () => $this->runs->setExGratia(($this->line)($high), 5000, '', $this->hr))->toThrow(RuntimeException::class, 'Give a reason');
    $this->runs->setExGratia(($this->line)($high), 5000, 'Festival ex gratia above the ceiling', $this->hr);
    expect(($this->line)($high)->total())->toBe(5000.0)->and((float) ($this->line)($high)->bonus_amount)->toBe(0.0);

    // One's own bonus is never changed by oneself.
    expect(fn () => $this->runs->setExGratia(($this->line)($own), 100, 'Me', $own->user->fresh()))->toThrow(RuntimeException::class, 'You cannot change your own bonus');

    // Process: the payroll month must be open; a finalized month is refused.
    expect(fn () => $this->runs->submit($run, null, '2026-09', $this->hr))->toThrow(RuntimeException::class, 'The payroll of Sep 2026 is already finalized');
    $result = $this->runs->submit($run, null, '2026-10', $this->hr);
    expect($result['submitted'])->toBe(3)->and($result['late'])->toBeFalse()
        ->and(($this->line)($standard)->status)->toBe('submitted')->and(($this->line)($standard)->pay_month)->toBe('2026-10');

    // Not yet approved: the October payroll pays nothing.
    $runs = app(PayrollRuns::class);
    $october = $runs->open($this->company, 2026, 10, $this->hr);
    $runs->calculate($october, $this->hr);
    expect(PayrollEntryLine::query()->whereIn('payroll_entry_id', $october->entries()->select('id'))->whereIn('classification', ['bonus', 'ex_gratia'])->count())->toBe(0);

    // The person who sent it cannot approve it; the employee cannot approve their own.
    expect(fn () => $this->runs->approve($run, null, $this->hr))->toThrow(RuntimeException::class, 'the person who prepared or sent this bonus cannot approve it')
        ->and(fn () => $this->runs->approve($run, [($this->line)($own)->id], $own->user->fresh()))->toThrow(RuntimeException::class, 'you cannot approve your own bonus')
        ->and(fn () => $this->runs->approve($run, null, tenantUser($this->tenant, ['payroll.view', 'payroll.calculate'])))->toThrow(AuthorizationException::class)
        ->and(($this->line)($standard)->status)->toBe('submitted');
});

it('settles approved bonus when the payroll of its month is finalized and never pays it twice', function () {
    po22VerifyBonusRule();
    $standard = statutoryEmployee(240000, $this->establishment, '100200300641');
    $high = statutoryEmployee(900000, $this->establishment, '100200300642');
    foreach ([7, 8, 9] as $month) {
        ($this->payroll)(2026, $month);
    }
    $run = $this->runs->prepare($this->company, '2026-27', $this->hr);
    $this->runs->setExGratia(($this->line)($high), 5000, 'Festival ex gratia above the ceiling', $this->hr);
    $this->runs->submit($run, null, '2026-10', $this->hr);
    expect($this->runs->approve($run, null, $this->approver)['approved'])->toBe(2)
        ->and(($this->line)($standard)->status)->toBe('processed')->and((int) ($this->line)($standard)->approved_by)->toBe($this->approver->id);

    $runs = app(PayrollRuns::class);
    $preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $october = $runs->calculate($runs->open($this->company, 2026, 10, $preparer), $preparer);
    $lines = fn (Employee $e) => PayrollEntryLine::query()->whereIn('payroll_entry_id', PayrollEntry::query()->where('payroll_run_id', $october->id)->where('employee_id', $e->id)->select('id'))->get();
    $bonus = $lines($standard)->firstWhere('classification', 'bonus');
    $exGratia = $lines($high)->firstWhere('classification', 'ex_gratia');
    expect((float) $bonus->amount)->toBe(1750.0)->and($bonus->code)->toBe('BONUS')->and($bonus->basis['source'])->toBe(['key' => 'bonus', 'ids' => [($this->line)($standard)->id]])
        ->and((float) $exGratia->amount)->toBe(5000.0)->and($exGratia->code)->toBe('EXGRATIA')
        ->and($lines($high)->firstWhere('classification', 'bonus'))->toBeNull();
    // Ex gratia is not statutory bonus paid.
    $entry = PayrollEntry::query()->with('lines')->where('payroll_run_id', $october->id)->where('employee_id', $high->id)->sole();
    expect(BonusBasis::paid($entry))->toBe(0.0);

    // An approved payroll pays it: unprocess is refused.
    $october = $runs->approve($runs->validate($october), $this->approver, 'ok');
    expect(fn () => $this->runs->unprocess($run, [($this->line)($standard)->id], 'Changed our mind', $this->hr))->toThrow(RuntimeException::class, 'the approved payroll of Oct 2026 pays it');

    $runs->finalize($october, $this->approver);
    $s = ($this->line)($standard);
    expect($s->status)->toBe('paid')->and((int) $s->paid_payroll_run_id)->toBe($october->id)->and((float) $s->paid_bonus)->toBe(1750.0)->and((float) $s->paid_ex_gratia)->toBe(0.0)
        ->and(($this->line)($high)->status)->toBe('paid')->and((float) ($this->line)($high)->paid_ex_gratia)->toBe(5000.0)
        ->and(fn () => $this->runs->unprocess($run, [$s->id], 'Too late', $this->hr))->toThrow(RuntimeException::class, 'already paid');

    // November pays nothing again; a recalculation leaves paid lines as they are.
    $november = $runs->calculate($runs->open($this->company, 2026, 11, $preparer), $preparer);
    expect(PayrollEntryLine::query()->whereIn('payroll_entry_id', $november->entries()->select('id'))->whereIn('classification', ['bonus', 'ex_gratia'])->count())->toBe(0);
    $this->runs->prepare($this->company, '2026-27', $this->hr);
    expect(($this->line)($standard)->status)->toBe('paid')->and(collect(PayrollInputs::sources())->map(fn ($s) => $s->key()))->toContain(BonusInput::KEY);
});

it('unprocesses processed bonus not yet paid, and a payroll calculated before that still settles what it paid', function () {
    po22VerifyBonusRule();
    $standard = statutoryEmployee(240000, $this->establishment, '100200300651');
    $low = statutoryEmployee(180000, $this->establishment, '100200300652');
    foreach ([7, 8, 9] as $month) {
        ($this->payroll)(2026, $month);
    }
    $run = $this->runs->prepare($this->company, '2026-27', $this->hr);
    $this->runs->submit($run, null, '2026-10', $this->hr);

    // Sent back by the approver: not processed again, with the note.
    expect($this->runs->sendBack($run, [($this->line)($low)->id], $this->approver, 'Check the pay rate'))->toBe(1)
        ->and(($this->line)($low)->status)->toBe('pending')->and(($this->line)($low)->return_note)->toBe('Check the pay rate');
    $this->runs->approve($run, null, $this->approver);

    // Unprocessed before any payroll carries it: back to Non processed, and October pays nothing.
    $result = $this->runs->unprocess($run, [($this->line)($standard)->id], 'Pay it in November instead', $this->hr);
    expect($result['unprocessed'])->toBe(1)->and($result['recalculate'])->toBe([])
        ->and(($this->line)($standard)->status)->toBe('pending')->and(($this->line)($standard)->pay_month)->toBeNull();

    // Processed again for October, calculated, then unprocessed: allowed while the payroll is not approved, with a
    // reminder to recalculate it; finalized without recalculating, that payroll paid the bonus, so the line is settled.
    $this->runs->submit($run, null, '2026-10', $this->hr);
    $this->runs->approve($run, null, $this->approver);
    $runs = app(PayrollRuns::class);
    $preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $october = $runs->calculate($runs->open($this->company, 2026, 10, $preparer), $preparer);
    expect(fn () => $this->runs->setExGratia(($this->line)($low), 100, 'x', $this->hr))->toThrow(RuntimeException::class, 'Only a bonus not yet processed can be changed');
    $result = $this->runs->unprocess($run, [($this->line)($standard)->id, ($this->line)($low)->id], 'Hold for review', $this->hr);
    expect($result['unprocessed'])->toBe(2)->and($result['recalculate'])->toBe(['the payroll of Oct 2026'])
        ->and(fn () => $this->runs->setExGratia(($this->line)($low), 100, 'x', $this->hr))->toThrow(RuntimeException::class, 'still in the payroll of Oct 2026 being prepared');
    $runs->finalize($runs->approve($runs->validate($october), $this->approver, 'ok'), $this->approver);
    expect(($this->line)($standard)->status)->toBe('paid')->and((float) ($this->line)($standard)->paid_bonus)->toBe(1750.0);
});

it('opens Process bonus on the payroll desk for payroll operators, with its tabs and actions', function () {
    po22VerifyBonusRule();
    $standard = statutoryEmployee(240000, $this->establishment, '100200300661');
    $high = statutoryEmployee(900000, $this->establishment, '100200300662');
    foreach ([7, 8, 9] as $month) {
        ($this->payroll)(2026, $month);
    }

    $this->get(BonusProcessing::getUrl(['year' => '2026-27', 'action' => 'prepareBonus']))->assertOk()->assertSee('Prepare bonus')->assertSee('Non processed bonus');
    Livewire::withQueryParams(['year' => '2026-27'])->test(BonusProcessing::class)
        ->assertActionVisible('prepareBonus')->assertActionHidden('processBonus')
        ->callAction('prepareBonus', ['company' => (string) $this->company->id, 'year' => '2026-27'])->assertNotified('Bonus prepared for FY 2026-27');
    $line = ($this->line)($standard);

    $page = Livewire::withQueryParams(['year' => '2026-27'])->test(BonusProcessing::class)
        ->assertCanSeeTableRecords([$line, ($this->line)($high)])
        ->assertTableActionVisible('payRate', $line)->assertTableActionHidden('payRate', ($this->line)($high))
        ->callTableAction('payRate', $line, ['pay_rate' => 7500, 'reason' => 'State minimum wage'])->assertNotified('Pay rate changed')
        ->callTableAction('exGratia', ($this->line)($high), ['amount' => 2500, 'reason' => 'Long service'])->assertNotified('Ex gratia saved')
        ->callAction('processBonus', ['pay_month' => '2026-10'])->assertNotified('2 bonus line(s) sent for approval');
    expect((float) $line->refresh()->statutory_bonus)->toBe(1875.0)->and($line->status)->toBe('submitted');
    $detail = (new BonusProcessing)->detailRows($line);
    expect($detail['Bonus percentage'])->toBe('8.33%')->and($detail['Pay rate'])->toContain('7,500.00 (changed: State minimum wage)')
        ->and($detail['Bonus pay month'])->toBe('Oct 2026')->and($detail['Pay group'])->toBe('Standard structure')->and($detail['Tax treatment'])->toStartWith('Taxable');

    // The preparer cannot approve; the approver can, from the header.
    Livewire::withQueryParams(['year' => '2026-27'])->test(BonusProcessing::class)->callAction('approveBonus', ['note' => ''])->assertNotified('Not done');
    $this->actingAs($this->approver);
    Livewire::withQueryParams(['year' => '2026-27'])->test(BonusProcessing::class)->callAction('approveBonus', ['note' => 'Checked'])->assertNotified('2 bonus line(s) approved');
    Livewire::withQueryParams(['year' => '2026-27', 'tab' => 'processed'])->test(BonusProcessing::class)
        ->assertCanSeeTableRecords([$line->refresh()])->assertTableActionVisible('unprocess', $line)
        ->callTableAction('unprocess', $line, ['reason' => 'Wrong month'])->assertNotified('1 bonus line(s) unprocessed');
    expect($line->refresh()->status)->toBe('pending');
    $this->actingAs($this->hr);
    Livewire::withQueryParams(['year' => '2026-27'])->test(BonusProcessing::class)
        ->callTableBulkAction('processSelected', [$line], ['pay_month' => '2026-11'])->assertNotified('1 bonus line(s) sent for approval');
    expect($line->refresh()->status)->toBe('submitted')->and($line->pay_month)->toBe('2026-11');
    $this->actingAs($this->approver);
    Livewire::withQueryParams(['year' => '2026-27'])->test(BonusProcessing::class)
        ->callTableBulkAction('sendBackSelected', [$line], ['note' => 'Not this month'])->assertNotified('1 bonus line(s) sent back');
    expect($line->refresh()->status)->toBe('pending');

    // The desk lists it; payroll viewers who neither calculate nor approve, and employees, do not get the page.
    $item = collect(collect((new PayrollDesk)->deskSections())->firstWhere('label', 'Payroll processing')['items'])->firstWhere('label', 'Process bonus');
    expect($item['url'])->toBe(BonusProcessing::getUrl())->and($item['count'])->toBe(0);
    $this->actingAs(tenantUser($this->tenant, ['payroll.view', 'payroll.approve']));
    expect(BonusProcessing::canAccess())->toBeTrue();
    Livewire::withQueryParams(['year' => '2026-27'])->test(BonusProcessing::class)->assertActionHidden('prepareBonus');
    $this->actingAs(tenantUser($this->tenant, ['payroll.view']));
    $this->get(BonusProcessing::getUrl())->assertForbidden();
    $this->actingAs($standard->user->fresh());
    $this->get(BonusProcessing::getUrl())->assertForbidden();
});
