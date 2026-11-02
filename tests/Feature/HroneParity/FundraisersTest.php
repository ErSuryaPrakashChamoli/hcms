<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\FundraiserCampaign;
use App\Domain\Engagement\Models\FundraiserPledge;
use App\Domain\Engagement\Services\Fundraisers;
use App\Domain\Experience\Services\HomeComposer;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\EngageDesk;
use App\Filament\Pages\Home;
use App\Filament\Resources\FundraiserCampaigns\FundraiserCampaignResource;
use App\Filament\Resources\FundraiserCampaigns\Pages\ListFundraiserCampaigns;
use App\Filament\Resources\FundraiserCampaigns\Pages\ViewFundraiserCampaign;
use App\Filament\Support\FundraiserActions;
use App\Http\Controllers\ParityFileController;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne parity GO-18 — Engage → "Initiate fundraise": fundraiser campaigns open to an audience, pledges paid outside
 * payroll or (owner decision D-GO3, off by default) deducted once from salary with consent per pledge through the
 * tagged payroll input FundraiserDeductionInput, totals, the screens, tenant isolation and permission gates. */

function go18Campaign(array $attributes = [], bool $open = true): FundraiserCampaign
{
    $fundraisers = app(Fundraisers::class);
    $campaign = $fundraisers->create($attributes + ['name' => 'Flood relief', 'purpose' => 'Help the families hit by the floods.', 'starts_on' => '2026-10-01',
        'ends_on' => '2026-11-30', 'target_amount' => '10000'], test()->hr);

    return $open ? $fundraisers->open($campaign, test()->hr) : $campaign;
}

beforeEach(function () {
    $this->travelTo('2026-10-14 06:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->settings = app(SettingsRepository::class);
    $this->fundraisers = app(Fundraisers::class);
    $this->hr = tenantUser($this->tenant, ['engagement.manage', 'engagement.view', 'employee.view']);
    $this->asha = activeEmployee(null, ['engagement.participate']);
    $this->ravi = activeEmployee(null, ['engagement.participate']);
    $this->outsider = activeEmployee(null, ['engagement.participate']);
    $this->audience = ['audience_criteria' => ['employee_ids' => [$this->asha->id, $this->ravi->id]]];
});

it('opens a fundraiser to its audience only, pinned with the opener\'s scope, and keeps it as donors saw it', function () {
    $draft = go18Campaign($this->audience, open: false);
    expect($draft->status)->toBe('draft')->and($draft->currency)->toBe('INR')->and($draft->payroll_deduction)->toBeFalse()
        ->and($this->fundraisers->openFor($this->asha->user))->toHaveCount(0)
        ->and($this->asha->user->can('view', $draft))->toBeFalse()
        ->and(fn () => $this->fundraisers->pledge($draft, $this->asha->user, 100, 'paid_outside', null, false))->toThrow(EngagementRuleViolation::class, 'not open for pledges');

    $open = $this->fundraisers->open($draft, $this->hr);
    $ids = [$this->asha->id, $this->ravi->id];
    sort($ids);
    expect($open->status)->toBe('open')->and($open->scope_user_id)->toBe($this->hr->id)->and($open->audience_criteria)->toBe(['employee_ids' => $ids])
        ->and($this->fundraisers->openFor($this->asha->user)->pluck('id')->all())->toBe([$open->id])
        ->and($this->fundraisers->openFor($this->outsider->user))->toHaveCount(0)
        ->and($this->asha->user->can('view', $open))->toBeTrue()
        ->and($this->outsider->user->can('view', $open))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'AUDIENCE_USED')->where('metadata->purpose', 'fundraiser')->exists())->toBeTrue()
        ->and(fn () => $this->fundraisers->pledge($open, $this->outsider->user, 100, 'paid_outside', null, false))->toThrow(EngagementRuleViolation::class, 'not open to you')
        ->and(fn () => $this->fundraisers->open($open, $this->hr))->toThrow(EngagementRuleViolation::class, 'Only a draft')
        ->and(fn () => $this->fundraisers->update($open, ['name' => 'Changed'], $this->hr))->toThrow(EngagementRuleViolation::class, 'Only a draft')
        ->and(fn () => $open->update(['target_amount' => 1]))->toThrow(RuntimeException::class, 'Only a draft')
        ->and(fn () => $open->delete())->toThrow(RuntimeException::class, 'never deleted');

    // Not yet in its window; an audience nobody is in; a window already over.
    $later = go18Campaign(['name' => 'Winter drive', 'starts_on' => '2026-11-01'] + $this->audience);
    expect(fn () => $this->fundraisers->pledge($later, $this->asha->user, 100, 'paid_outside', null, false))->toThrow(EngagementRuleViolation::class, 'opens for pledges on 01 Nov 2026')
        ->and(fn () => go18Campaign(['name' => 'Nobody', 'audience_criteria' => ['lifecycle_states' => ['exited']]]))->toThrow(EngagementRuleViolation::class, 'reaches nobody')
        ->and(fn () => go18Campaign(['name' => 'Past', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']))->toThrow(EngagementRuleViolation::class, 'end date has passed')
        ->and(fn () => go18Campaign(['name' => 'Backwards', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-10']))->toThrow(EngagementRuleViolation::class, 'before the start date');

    // Closed: no more pledges, still visible to its audience; a cancelled one only to those who pledged.
    $this->fundraisers->close($open, $this->hr, 'Target reached');
    expect(fn () => $this->fundraisers->pledge($open->refresh(), $this->asha->user, 100, 'paid_outside', null, false))->toThrow(EngagementRuleViolation::class, 'not open for pledges')
        ->and($this->asha->user->can('view', $open->refresh()))->toBeTrue()
        ->and($this->fundraisers->openFor($this->asha->user)->pluck('id')->all())->toBe([]);
    $this->fundraisers->cancel($later, $this->hr, 'Postponed');
    expect($this->asha->user->can('view', $later->refresh()))->toBeFalse()->and($later->status)->toBe('cancelled');
});

it('takes pledges paid outside payroll and refuses deduction from salary while the organisation has it off', function () {
    expect($this->settings->get(Fundraisers::SETTING))->toBeFalse()
        ->and(fn () => go18Campaign(['payroll_deduction' => true] + $this->audience))->toThrow(EngagementRuleViolation::class, 'not deducted through payroll');
    $campaign = go18Campaign($this->audience);
    expect(fn () => $this->fundraisers->pledge($campaign, $this->asha->user, 500, 'one_time_payroll', '2026-10', true))->toThrow(EngagementRuleViolation::class, 'not deducted through payroll')
        ->and($this->fundraisers->payrollMonths($campaign, $this->asha))->toBe([])
        ->and(fn () => $this->fundraisers->pledge($campaign, $this->asha->user, 0, 'paid_outside', null, false))->toThrow(EngagementRuleViolation::class, 'positive amount')
        ->and(fn () => $this->fundraisers->pledge($campaign, $this->asha->user, '10.555', 'paid_outside', null, false))->toThrow(EngagementRuleViolation::class, 'two decimals')
        ->and(fn () => $this->fundraisers->pledge($campaign, $this->asha->user, 10, 'barter', null, false))->toThrow(EngagementRuleViolation::class, 'how you will pay');

    $pledge = $this->fundraisers->pledge($campaign, $this->asha->user, '500', 'paid_outside', null, false, 'For the Rao family');
    expect($pledge->status)->toBe('pledged')->and((float) $pledge->amount)->toBe(500.0)->and($pledge->currency)->toBe('INR')
        ->and($pledge->consent_given_at)->toBeNull()->and($pledge->payroll_month)->toBeNull()
        ->and(fn () => $this->fundraisers->recordPayment($pledge, $this->asha->user, '2026-10-14'))->toThrow(EngagementRuleViolation::class, 'engagement.manage')
        ->and(fn () => $this->fundraisers->recordPayment($pledge, $this->hr, '2026-10-20'))->toThrow(EngagementRuleViolation::class, 'future')
        ->and(fn () => $pledge->update(['amount' => 600]))->toThrow(RuntimeException::class, 'keeps the amount')
        ->and(fn () => $pledge->delete())->toThrow(RuntimeException::class, 'never deleted');

    $paid = $this->fundraisers->recordPayment($pledge, $this->hr, '2026-10-14', 'UPI 4411');
    expect($paid->status)->toBe('paid')->and($paid->paid_on->toDateString())->toBe('2026-10-14')->and($paid->payment_reference)->toBe('UPI 4411')
        ->and(fn () => $this->fundraisers->cancelPledge($paid, $this->asha->user))->toThrow(EngagementRuleViolation::class, 'still to be collected')
        // The donor's message stays out of the audit trail.
        ->and(AuditEvent::query()->with('fieldChanges')->get()->toJson())->not->toContain('Rao family');

    // The donor or HR cancels a pledge still to be collected; nobody else.
    $second = $this->fundraisers->pledge($campaign, $this->ravi->user, 300, 'paid_outside', null, false);
    expect(fn () => $this->fundraisers->cancelPledge($second, $this->asha->user))->toThrow(EngagementRuleViolation::class, 'Only the donor or HR');
    expect($this->fundraisers->cancelPledge($second, $this->ravi->user, 'Paid elsewhere')->status)->toBe('cancelled');
});

it('deducts a consented payroll pledge once in its month, exactly as pledged, and never a withdrawn one', function () {
    syncComplianceRules();
    foreach ([$this->asha, $this->ravi, $this->outsider] as $gone) {
        forceLifecycle($gone, LifecycleState::Exited, ['exit_date' => '2025-12-31']);
    }
    $preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($preparer);
    $company = payrollCompany();
    $runs = app(PayrollRuns::class);
    $employee = salariedEmployee(600000, ['engagement.participate']);
    EmployeeBankAccount::create(['employee_id' => $employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $calculate = fn (int $month): PayrollRun => $runs->calculate($runs->open($company, 2026, $month, $preparer), $preparer);
    $lines = fn (PayrollRun $run) => PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $run->id))->where('basis->source->key', 'fundraiser_pledge')->orderBy('id')->get();
    $entry = fn (PayrollRun $run) => PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->sole();

    $baseline = $entry($calculate(10));
    $this->settings->set(Fundraisers::SETTING, true, 'Collect pledges with salaries');
    $campaign = go18Campaign(['payroll_deduction' => true, 'audience_criteria' => ['employee_ids' => [$employee->id]]]);
    $pledge = fn (float $amount, ?string $month, bool $consent = true, string $mode = 'one_time_payroll') => $this->fundraisers->pledge($campaign, $employee->user, $amount, $mode, $month, $consent);
    // Off for this fundraiser: only outside payment.
    $outsideOnly = go18Campaign(['name' => 'Book drive', 'audience_criteria' => ['employee_ids' => [$employee->id]]]);
    expect(array_keys($this->fundraisers->payrollMonths($campaign, $employee)))->toContain('2026-10', '2026-11')
        ->and($this->fundraisers->payrollMonths($outsideOnly, $employee))->toBe([])
        ->and(fn () => $this->fundraisers->pledge($outsideOnly, $employee->user, 100, 'one_time_payroll', '2026-10', true))->toThrow(EngagementRuleViolation::class, 'does not collect through payroll')
        ->and(fn () => $pledge(1500, '2026-10', false))->toThrow(EngagementRuleViolation::class, 'needs your consent')
        ->and(fn () => $pledge(1500, null))->toThrow(EngagementRuleViolation::class, 'Choose the payroll month')
        ->and(fn () => $pledge(1500, '2025-01'))->toThrow(EngagementRuleViolation::class, 'not open for you');

    $october = $pledge(1500, '2026-10');
    $withdrawn = $pledge(200, '2026-10');
    $late = $pledge(250, '2026-10');
    $november = $pledge(300, '2026-11');
    $outside = $pledge(100, null, false, 'paid_outside');
    expect($october->consent_given_at)->not->toBeNull()->and($october->consent_text)->toContain('1,500.00 INR')->toContain('Oct 2026')->toContain('Flood relief')
        ->and($outside->consent_given_at)->toBeNull();
    // Cancelling before the payroll month is calculated removes it from the input.
    $this->fundraisers->cancelPledge($withdrawn, $employee->user, 'Changed my mind');

    $run = $calculate(10);
    expect($lines($run)->map(fn ($l) => [$l->code, $l->type, (float) $l->amount, $l->basis['source']])->all())->toEqual([
        ['FUNDRAISER', 'deduction', 1500.0, ['key' => 'fundraiser_pledge', 'ids' => [$october->id]]],
        ['FUNDRAISER', 'deduction', 250.0, ['key' => 'fundraiser_pledge', 'ids' => [$late->id]]],
    ])
        ->and((float) $entry($run)->net_pay)->toBe(round((float) $baseline->net_pay - 1750, 2))
        ->and((float) $entry($run)->gross)->toBe((float) $baseline->gross)
        ->and($this->fundraisers->carriedBy($october->refresh()))->toContain('Oct 2026')
        ->and(fn () => $this->fundraisers->recordPayment($october, $this->hr, '2026-10-14'))->toThrow(EngagementRuleViolation::class, 'deducts this pledge');
    // Another month's run leaves them to the run that carries them and takes only its own month.
    expect($lines($calculate(11))->map(fn ($l) => $l->basis['source']['ids'])->all())->toBe([[$november->id]]);

    // Withdrawn after the run was calculated: the run cannot be finalized until it is recalculated without it.
    $this->fundraisers->cancelPledge($late, $employee->user, 'Paying cash instead');
    $approved = $runs->approve($runs->validate($run->refresh()), $approver, 'ok');
    expect(fn () => $runs->finalize($approved, $approver))->toThrow(RuntimeException::class, 'recalculate the run before finalizing')
        ->and($october->refresh()->status)->toBe('pledged')->and($approved->refresh()->status)->toBe('approved');
    $run = $runs->calculate($runs->reopen($approved, 'Pledge withdrawn', $approver), $preparer);
    expect($lines($run)->map(fn ($l) => $l->basis['source']['ids'])->all())->toBe([[$october->id]]);
    $runs->finalize($runs->approve($runs->validate($run), $approver, 'ok'), $approver);

    expect($october->refresh()->status)->toBe('deducted')->and($october->payroll_run_id)->toBe($run->id)->and($october->deducted_at)->not->toBeNull()
        ->and($late->refresh()->status)->toBe('cancelled')->and($late->payroll_run_id)->toBeNull()
        ->and($withdrawn->refresh()->status)->toBe('cancelled')
        ->and(fn () => $this->fundraisers->cancelPledge($october, $employee->user))->toThrow(EngagementRuleViolation::class, 'still to be collected')
        // Paid once: the next month's payroll takes only that month's pledge.
        ->and($lines($calculate(11))->map(fn ($l) => $l->basis['source']['ids'])->all())->toBe([[$november->id]])
        ->and($this->fundraisers->totals($campaign))->toMatchArray(['collected' => 1500.0, 'pledged' => 400.0, 'donors' => 1]);

    // Switched off again: nothing more is deducted (the pledge waits to be paid outside or cancelled).
    $this->settings->set(Fundraisers::SETTING, false, 'Outside payroll only');
    $november11 = $calculate(11);
    expect($lines($november11))->toHaveCount(0)->and($entry($november11)->inputs['input_sources']['fundraiser_pledge'] ?? null)->toBeNull();
});

it('totals collected against target over every pledge, and exports the pledges HR may see', function () {
    $campaign = go18Campaign($this->audience);
    $paid = $this->fundraisers->pledge($campaign, $this->asha->user, 500, 'paid_outside', null, false, '=HYPERLINK("x")');
    $this->fundraisers->recordPayment($paid, $this->hr, '2026-10-14');
    $this->fundraisers->pledge($campaign, $this->ravi->user, 300.5, 'paid_outside', null, false);
    $this->fundraisers->cancelPledge($this->fundraisers->pledge($campaign, $this->asha->user, 200, 'paid_outside', null, false), $this->asha->user);

    expect($this->fundraisers->totals($campaign))->toBe(['target' => 10000.0, 'collected' => 500.0, 'pledged' => 300.5, 'donors' => 2, 'pledges' => 2, 'percent' => 8]);
    $csv = $this->fundraisers->pledgesCsv($campaign, $this->hr);
    expect(substr_count(trim($csv), "\n"))->toBe(3)->and($csv)->toContain('Flood relief')->toContain("'=HYPERLINK")->toContain('Paid outside payroll')
        ->and(AuditEvent::query()->where('action', 'EXPORT')->where('metadata->export', 'fundraiser_pledges')->exists())->toBeTrue()
        ->and(fn () => $this->fundraisers->pledgesCsv($campaign, $this->asha->user))->toThrow(EngagementRuleViolation::class, 'engagement.manage');

    // Cancelling the fundraiser cancels what is still to be collected; what was paid stays.
    $result = $this->fundraisers->cancel($campaign, $this->hr, 'Beneficiary helped by the state');
    expect($result['pledges'])->toBe(1)->and($this->fundraisers->totals($campaign))->toMatchArray(['collected' => 500.0, 'pledged' => 0.0, 'donors' => 1])
        ->and($paid->refresh()->status)->toBe('paid')
        ->and(collect(DataInventory::tableCategories())->only(['fundraiser_campaigns', 'fundraiser_pledges'])->unique()->values()->all())->toBe(['engagement']);
});

it('keeps fundraisers, pledges and their documents inside their tenant', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    Storage::disk('local')->put(Fundraisers::directory().'/appeal.pdf', '%PDF-1.4 appeal');
    expect(fn () => go18Campaign(['document_path' => 'tenants/999/fundraisers/x.pdf'] + $this->audience))->toThrow(EngagementRuleViolation::class, 'Attach the document again');
    $campaign = go18Campaign(['document_path' => Fundraisers::directory().'/appeal.pdf', 'document_name' => 'appeal.pdf'] + $this->audience);
    $this->fundraisers->pledge($campaign, $this->asha->user, 500, 'paid_outside', null, false);
    $url = ParityFileController::url('fundraiser', $campaign, 0);

    $this->actingAs($this->asha->user)->get($url)->assertOk();
    $this->actingAs($this->outsider->user)->get($url)->assertForbidden();

    $other = provisionTenant('Other');
    actAsTenant($other);
    $stranger = tenantUser($other, ['engagement.manage', 'engagement.participate']);
    expect(FundraiserCampaign::query()->count())->toBe(0)->and(FundraiserPledge::query()->count())->toBe(0)
        ->and(app(Fundraisers::class)->totalsFor([$campaign->id])[$campaign->id])->toMatchArray(['collected' => 0.0, 'pledged' => 0.0, 'donors' => 0]);
    actAsTenant(null);
    $this->actingAs($stranger)->get($url)->assertNotFound();
});

it('opens the screens only for those who may use them: HR runs fundraisers, employees see the ones open to them', function () {
    $this->actingAs($this->asha->user);
    expect(FundraiserCampaignResource::canAccess())->toBeFalse();
    $this->get(FundraiserCampaignResource::getUrl('index'))->assertForbidden();

    // HR starts one with the wizard opened by its URL (?action=startFundraiser), as the Actions launcher links it.
    $this->actingAs($this->hr);
    expect(FundraiserCampaignResource::canAccess())->toBeTrue()->and(EngageDesk::canAccess())->toBeTrue()
        ->and(collect((new EngageDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'url'))->all())
        ->toContain(FundraiserCampaignResource::getUrl('index', ['action' => FundraiserActions::START]));
    $this->get(FundraiserCampaignResource::getUrl('index', ['action' => FundraiserActions::START]))->assertOk()->assertSee('mountAction(', false);
    Livewire::withQueryParams(['action' => FundraiserActions::START])->test(ListFundraiserCampaigns::class)->assertSet('defaultAction', FundraiserActions::START)
        ->mountAction(FundraiserActions::START)->assertActionMounted(FundraiserActions::START);
    Livewire::withQueryParams([]);
    Livewire::test(ListFundraiserCampaigns::class)->callAction(FundraiserActions::START, [
        'name' => 'Cyclone relief', 'purpose' => 'Rebuild homes on the coast.', 'starts_on' => '2026-10-14', 'ends_on' => '2026-12-31', 'target_amount' => 50000,
        'currency' => 'INR', 'audience_criteria' => ['employee_ids' => [$this->asha->id]], 'open_now' => true,
    ])->assertHasNoActionErrors();
    $campaign = FundraiserCampaign::query()->where('name', 'Cyclone relief')->sole();
    expect($campaign->status)->toBe('open')->and($campaign->audience_criteria)->toBe(['employee_ids' => [$this->asha->id]]);
    Livewire::test(ViewFundraiserCampaign::class, ['record' => $campaign->getRouteKey()])->assertOk()->assertSee('Collected against target')->assertSee('50,000.00 INR');

    // The employee in its audience now reaches the fundraisers and donates from Home; others still do not.
    $this->fundraisers->forget();
    $this->actingAs($this->asha->user);
    expect(FundraiserCampaignResource::canAccess())->toBeTrue()
        ->and(app(HomeComposer::class)->for($this->asha->user)['fundraisers']['campaigns'][0])->toMatchArray(['id' => $campaign->id, 'target' => 50000.0, 'collected' => 0.0]);
    Livewire::test(ListFundraiserCampaigns::class)->assertCanSeeTableRecords([$campaign])->assertActionHidden(FundraiserActions::START);
    Livewire::test(Home::class)->assertSee('Fundraisers')->assertSee('Donate now')
        ->callAction('donate', ['amount' => 750, 'note' => 'Stay strong'], ['campaign' => $campaign->id])->assertHasNoActionErrors();
    expect(FundraiserPledge::query()->where('employee_id', $this->asha->id)->sole())->amount->toEqual('750.00')->mode->toBe('paid_outside')
        ->and(fn () => $this->fundraisers->open($campaign, $this->asha->user))->toThrow(EngagementRuleViolation::class, 'engagement.manage')
        ->and(fn () => $this->fundraisers->create(['name' => 'Mine'], $this->asha->user))->toThrow(EngagementRuleViolation::class, 'engagement.manage')
        ->and(fn () => $this->fundraisers->close($campaign, $this->asha->user))->toThrow(EngagementRuleViolation::class, 'engagement.manage');

    $this->actingAs($this->outsider->user);
    $this->fundraisers->forget();
    expect(FundraiserCampaignResource::canAccess())->toBeFalse()->and(app(HomeComposer::class)->for($this->outsider->user)['fundraisers'])->toBeNull();
    // Outside their list: the fundraiser is not found for them (its existence is not revealed).
    $this->get(FundraiserCampaignResource::getUrl('view', ['record' => $campaign]))->assertNotFound();

    // Without engagement.participate nobody pledges, even in the audience.
    $silent = activeEmployee(null, ['task.view']);
    $named = go18Campaign(['name' => 'Named', 'audience_criteria' => ['employee_ids' => [$silent->id]]]);
    expect(fn () => $this->fundraisers->pledge($named, $silent->user, 100, 'paid_outside', null, false))->toThrow(EngagementRuleViolation::class, 'engagement.participate');
});
