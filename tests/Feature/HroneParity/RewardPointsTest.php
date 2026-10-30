<?php

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Models\RewardPointEntry;
use App\Domain\Recognition\Services\Recognitions;
use App\Domain\Recognition\Services\RewardPoints;
use App\Filament\Resources\RewardPoints\Pages\ListRewardPointEntries;
use App\Filament\Resources\RewardPoints\RewardPointEntryResource;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity HP-9d — Engage → Reward points: the points value and currency (one-time), the reward points policy
 * (cycle credits, eligibility, limits, carry forward, expiry), the points budget, awards, badge-linked points and
 * redemption through payroll (RewardPointsInput), off by default with payroll results unchanged. */

function hp9dPolicy(array $settings, string $code = 'RP_ALL'): Policy
{
    $policy = Policy::create(['type' => 'reward_points', 'name' => 'Maximum Disbursal', 'code' => $code]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'reward_points', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);

    return $policy;
}

/** @return array<string, int> wallet => sum */
function hp9dWallets(?int $employeeId = null): array
{
    return RewardPointEntry::query()->withoutGlobalScopes()->where('tenant_id', test()->tenant->id)->where('employee_id', $employeeId)
        ->selectRaw('wallet, sum(points) as total')->groupBy('wallet')->pluck('total', 'wallet')->map(fn ($v) => (int) $v)->all();
}

beforeEach(function () {
    $this->travelTo('2026-10-14 06:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->settings = app(SettingsRepository::class);
    $this->points = app(RewardPoints::class);
    $this->hr = tenantUser($this->tenant, ['recognition.view', 'recognition.give', 'recognition.manage', 'recognition.points', 'settings.view', 'settings.update', 'policy.view']);
    $this->manager = activeEmployee(null, ['recognition.view', 'recognition.give']);
    $this->asha = activeEmployee($this->manager, ['recognition.view', 'recognition.give']);
    $this->ravi = activeEmployee($this->manager, ['recognition.view', 'recognition.give']);
});

it('keeps reward points off by default: no credits, no awards, no redemption, no screen for employees', function () {
    hp9dPolicy(['credit_cycle' => 'monthly', 'points_per_cycle' => 100]);

    expect($this->points->enabled())->toBeFalse()
        ->and($this->points->credit($this->asha)['status'])->toBe('no_policy')
        ->and(RewardPointEntry::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(fn () => $this->points->give($this->asha->user, $this->ravi, 5))->toThrow(RuntimeException::class, 'switched off')
        ->and(fn () => $this->points->redeem($this->asha, 5, $this->hr))->toThrow(RuntimeException::class, 'switched off')
        ->and($this->points->redemptionRefusal())->toBe('Reward points are switched off.');

    Artisan::call('peopleos:engage:credit-points', ['--tenant' => $this->tenant->slug]);
    expect(Artisan::output())->toContain('reward points are off')->and(RewardPointEntry::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->actingAs($this->asha->user);
    expect(RewardPointEntryResource::canAccess())->toBeFalse();
    $this->actingAs($this->hr);
    expect(RewardPointEntryResource::canAccess())->toBeTrue();
});

it('locks the points value and currency once the ledger has a row (one-time setting)', function () {
    $catalogue = app(SetupCatalogue::class);
    $field = fn (string $key) => SetupCatalogue::field($key);
    $value = fn () => collect($catalogue->settingsFor('engage'))->firstWhere('key', 'engage.points.value_per_point');

    expect($value()['value'])->toBeNull()->and($value()['locked'])->toBeFalse()
        ->and($catalogue->save('engage', [$field('engage.points.value_per_point') => '', $field('engage.points.currency') => ''], 'Nothing', $this->hr))->toBe([])
        ->and(fn () => $catalogue->save('engage', [$field('engage.points.currency') => 'inr'], 'Lower case', $this->hr))->toThrow(RuntimeException::class, 'expected format');

    expect($catalogue->save('engage', [$field('engage.points.value_per_point') => '0.5', $field('engage.points.currency') => 'INR'], 'Points are worth 50 paise', $this->hr))
        ->toBe(['engage.points.value_per_point', 'engage.points.currency'])
        ->and($this->points->valuePerPoint())->toBe(0.5)->and($this->points->currency())->toBe('INR');

    $this->points->deposit(1000, $this->hr, 'Opening budget');
    expect($value()['locked'])->toBeTrue()
        ->and(fn () => $catalogue->save('engage', [$field('engage.points.value_per_point') => '2'], 'Change', $this->hr))->toThrow(RuntimeException::class, 'one-time setting')
        ->and(fn () => $catalogue->save('engage', [$field('engage.points.currency') => 'USD'], 'Change', $this->hr))->toThrow(RuntimeException::class, 'one-time setting')
        // An unchanged value passes, and other settings still save.
        ->and($catalogue->save('engage', [$field('engage.points.value_per_point') => '0.5', $field('engage.points.enabled') => true], 'Switch on', $this->hr))->toBe(['engage.points.enabled']);
});

it('checks the reward points policy before it is published', function () {
    $bad = Policy::create(['type' => 'reward_points', 'name' => 'Bad', 'code' => 'RP_BAD']);
    app(Policies::class)->draft($bad, ['credit_cycle' => 'weekly']);
    expect(fn () => app(Policies::class)->publish($bad))->toThrow(ConfigurationException::class, 'credit cycle');
    app(Policies::class)->draft($bad, ['credit_cycle' => 'monthly', 'eligibility_rules' => 1, 'eligibility' => []]);
    expect(fn () => app(Policies::class)->publish($bad))->toThrow(ConfigurationException::class, 'at least one eligibility row');
    app(Policies::class)->draft($bad, ['credit_cycle' => 'monthly', 'eligibility_rules' => 1, 'eligibility' => [['criterion' => 'tenure_months', 'from' => 12, 'to' => 6, 'points' => 5]]]);
    expect(fn () => app(Policies::class)->publish($bad))->toThrow(ConfigurationException::class, 'ends before it starts');
    app(Policies::class)->draft($bad, ['credit_cycle' => 'monthly', 'points_per_cycle' => -5]);
    expect(fn () => app(Policies::class)->publish($bad))->toThrow(ConfigurationException::class, 'cannot be negative');

    expect(config('peopleos_setup.modules.engage.policy_types'))->toContain('reward_points')
        ->and(collect(config('peopleos.policies.types.reward_points.fields'))->where('type', 'section')->pluck('label')->all())->toBe(['Basic', 'Reward points', 'Eligibility']);
});

it('credits each employee of the policy once per cycle from the budget, with eligibility rows, and only what the budget covers', function () {
    $this->settings->set('engage.points.enabled', true, 'On');
    hp9dPolicy(['credit_cycle' => 'monthly', 'points_per_cycle' => 100, 'eligibility_rules' => 1, 'eligibility' => [
        ['criterion' => 'tenure_months', 'from' => 0, 'to' => 6, 'points' => 50],
        ['criterion' => 'direct_reports', 'from' => 1, 'to' => null, 'points' => 200],
    ]]);
    $joiner = activeEmployee(null, ['recognition.view']);
    $joiner->update(['joining_date' => '2026-07-01']);

    $this->points->deposit(250, $this->hr);
    expect($this->points->credit($this->manager)['status'])->toBe('credited')                       // 200 (has direct reports)
        ->and($this->points->credit($this->asha)['status'])->toBe('budget')                          // 100 > 50 left
        ->and($this->points->credit($joiner->refresh())['status'])->toBe('credited')                 // 50 (3 months of service)
        ->and($this->points->credit($this->manager)['status'])->toBe('already')
        ->and($this->points->givingBalance($this->manager))->toBe(200)->and($this->points->givingBalance($joiner))->toBe(50)
        ->and($this->points->budgetAvailable())->toBe(0);

    // A deposit lets the next run credit whoever was waiting; the run is idempotent.
    $this->points->deposit(1000, $this->hr);
    Artisan::call('peopleos:engage:credit-points', ['--tenant' => $this->tenant->slug]);
    expect(Artisan::output())->toContain('2 employee(s) credited 200 point(s)')
        ->and($this->points->givingBalance($this->asha))->toBe(100)->and($this->points->givingBalance($this->ravi))->toBe(100);
    Artisan::call('peopleos:engage:credit-points', ['--tenant' => $this->tenant->slug]);
    expect(Artisan::output())->toContain('0 employee(s) credited')
        ->and(RewardPointEntry::query()->withoutGlobalScopes()->where('kind', 'credit')->where('employee_id', $this->asha->id)->pluck('cycle_key')->all())->toBe(['2026-10'])
        // deposits = budget + points to give + points received + redeemed + expired
        ->and(array_sum(hp9dWallets()) + RewardPointEntry::query()->withoutGlobalScopes()->whereNotNull('employee_id')->sum('points'))->toBe(1250);
});

it('lapses unused points back to the budget at the next cycle unless they carry forward, and expires points received', function () {
    $this->settings->set('engage.points.enabled', true, 'On');
    $policy = hp9dPolicy(['credit_cycle' => 'monthly', 'points_per_cycle' => 100, 'carry_forward' => 0, 'expiry_months' => 1]);
    $this->points->deposit(1000, $this->hr);
    $this->points->credit($this->asha);
    $this->points->give($this->asha->user, $this->ravi, 30, 'Thanks for the demo');

    $this->travelTo('2026-11-20 06:00:00');
    $result = $this->points->credit($this->asha->refresh());
    expect($result)->toMatchArray(['status' => 'credited', 'points' => 100, 'lapsed' => 70])
        ->and($this->points->givingBalance($this->asha))->toBe(100)
        ->and($this->points->budgetAvailable())->toBe(1000 - 100 + 70 - 100)
        // Ravi's 30 points received on 14 October are older than a month: they expire (oldest first).
        ->and($this->points->credit($this->ravi->refresh())['expired'])->toBe(30)
        ->and($this->points->earnedBalance($this->ravi))->toBe(0);

    // With carry forward the points stay.
    app(Policies::class)->draft($policy, ['credit_cycle' => 'monthly', 'points_per_cycle' => 100, 'carry_forward' => 1]);
    app(Policies::class)->publish($policy, '2026-12-01');
    $this->travelTo('2026-12-02 06:00:00');
    expect($this->points->credit($this->asha->refresh()))->toMatchArray(['status' => 'credited', 'lapsed' => 0])
        ->and($this->points->givingBalance($this->asha))->toBe(200);
});

it('gives points to a colleague within the balance, the reward limit and the receiver’s limit per cycle', function () {
    $this->settings->set('engage.points.enabled', true, 'On');
    hp9dPolicy(['credit_cycle' => 'monthly', 'points_per_cycle' => 100, 'max_per_award' => 40, 'max_received_per_cycle' => 50]);
    $this->points->deposit(1000, $this->hr);
    $this->points->credit($this->asha);
    $this->points->credit($this->manager);

    $entry = $this->points->give($this->asha->user, $this->ravi, 40, '<b>Great</b> demo');
    expect($entry->note)->toBe('Great demo')->and($entry->counterpart_employee_id)->toBe($this->asha->id)
        ->and($this->points->givingBalance($this->asha))->toBe(60)->and($this->points->earnedBalance($this->ravi))->toBe(40)
        ->and(NotificationDelivery::query()->where('event', 'recognition.points_received')->where('user_id', $this->ravi->user_id)->value('subject'))->toBe($this->asha->user->name.' gave you 40 reward points')
        ->and(fn () => $this->points->give($this->asha->user, $this->ravi, 41))->toThrow(RuntimeException::class, 'at most 40 points')
        ->and(fn () => $this->points->give($this->manager->user, $this->ravi, 20))->toThrow(RuntimeException::class, '10 left')
        ->and(fn () => $this->points->give($this->asha->user, $this->asha, 5))->toThrow(RuntimeException::class, 'yourself')
        ->and(fn () => $this->points->give($this->ravi->user, $this->asha, 5))->toThrow(RuntimeException::class, 'You have 0 points to give')
        ->and(fn () => $this->points->give(tenantUser($this->tenant, ['recognition.view']), $this->asha, 5))->toThrow(RuntimeException::class, 'cannot give reward points')
        ->and(fn () => $entry->update(['points' => 99]))->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $entry->refresh()->delete())->toThrow(RuntimeException::class, 'append-only');

    // Employees deposit or adjust nothing.
    expect(fn () => $this->points->deposit(10, $this->asha->user))->toThrow(RuntimeException::class, 'Only HR')
        ->and(fn () => $this->points->adjust($this->ravi, 10, $this->asha->user, 'x'))->toThrow(RuntimeException::class, 'cannot adjust');
    $this->points->adjust($this->ravi, -15, $this->hr, 'Correction');
    expect($this->points->earnedBalance($this->ravi))->toBe(25);

    // The screen: give points from the list.
    $this->actingAs($this->manager->user);
    Livewire::test(ListRewardPointEntries::class)->assertOk()->assertSee('Points to give: 100')
        ->callAction('givePoints', ['employee_id' => $this->asha->id, 'points' => '5', 'message' => 'Cheers'])->assertHasNoActionErrors();
    expect($this->points->earnedBalance($this->asha))->toBe(5);
});

it('pays a badge’s reward points from the budget and returns them when the badge is withdrawn', function () {
    $badge = Badge::query()->create(['code' => 'STAR', 'name' => 'Star', 'reward_points' => 25, 'show_points_on_wall' => true]);
    $recognitions = app(Recognitions::class);

    // Off: the badge is given without points.
    $recognitions->give($badge, $this->ravi, $this->asha->user, 'One');
    expect(RewardPointEntry::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->settings->set('engage.points.enabled', true, 'On');
    $this->points->deposit(30, $this->hr);
    $first = $recognitions->give($badge, $this->ravi, $this->asha->user, 'Two');
    expect($this->points->earnedBalance($this->ravi))->toBe(25)->and($this->points->budgetAvailable())->toBe(5)
        ->and(RewardPointEntry::query()->withoutGlobalScopes()->where('kind', 'badge_award')->value('source_id'))->toBe($first->id);

    // A budget too small for the points never stops the badge.
    $second = $recognitions->give($badge, $this->asha, $this->ravi->user, 'Three');
    expect($second->exists)->toBeTrue()->and($this->points->earnedBalance($this->asha))->toBe(0);

    $recognitions->withdraw($first, $this->hr, 'Given by mistake');
    expect($this->points->earnedBalance($this->ravi))->toBe(0)->and($this->points->budgetAvailable())->toBe(30);
});

it('redeems points through payroll only when allowed, pays them once as a taxable earning and keeps payroll unchanged otherwise', function () {
    syncComplianceRules();
    // Only the salaried employee below is paid (the others of the file have left).
    foreach ([$this->manager, $this->asha, $this->ravi] as $gone) {
        forceLifecycle($gone, LifecycleState::Exited, ['exit_date' => '2025-12-31']);
    }
    $preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($preparer);
    $company = payrollCompany();
    $runs = app(PayrollRuns::class);
    $employee = salariedEmployee(600000, ['recognition.view', 'recognition.give']);
    EmployeeBankAccount::create(['employee_id' => $employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $calculate = fn (int $month): PayrollRun => $runs->calculate($runs->open($company, 2026, $month, $preparer), $preparer);
    $lines = fn (PayrollRun $run) => PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $run->id))->where('basis->source->key', 'reward_points')->get();

    $baseline = PayrollEntry::query()->where('payroll_run_id', $calculate(10)->id)->where('employee_id', $employee->id)->sole();
    $this->settings->set('engage.points.enabled', true, 'On');
    $this->points->deposit(1000, $this->hr);
    $this->points->adjust($employee, 300, $this->hr, 'Hackathon winner');
    // Points earned but not redeemed change nothing; nor does a redemption that is not allowed.
    $run = $calculate(10);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->sole();
    expect(fn () => $this->points->redeem($employee, 100, $this->hr))->toThrow(RuntimeException::class, 'not redeemed')
        ->and($lines($run))->toHaveCount(0)->and($entry->inputs['input_sources'] ?? null)->toBeNull()
        ->and((float) $entry->net_pay)->toBe((float) $baseline->net_pay)->and((float) $entry->gross)->toBe((float) $baseline->gross)
        ->and($run->calculation_version)->toBe(PayrollCalculator::VERSION)->and(PayrollCalculator::VERSION)->toBe('payroll-2.5');

    $this->settings->set('engage.points.redeem_modes', ['payroll'], 'Redeem through payroll');
    expect(fn () => $this->points->redeem($employee, 100, $this->hr))->toThrow(RuntimeException::class, 'no money value');
    $this->settings->set('engage.points.value_per_point', 2, 'Two rupees a point');
    expect(fn () => $this->points->redeem($employee, 100, $employee->user))->toThrow(RuntimeException::class, 'Ask HR')
        ->and(fn () => $this->points->redeem($employee, 400, $this->hr))->toThrow(RuntimeException::class, 'Only 300 points');
    $redemption = $this->points->redeem($employee, 100, $this->hr);
    $this->settings->set('engage.points.self_redeem', true, 'Self service');
    $own = $this->points->redeem($employee, 50, $employee->user);
    $cancelled = $this->points->redeem($employee, 10, $this->hr);
    $this->points->cancelRedemption($cancelled, $this->hr, 'Asked by mistake');
    expect((float) $redemption->amount)->toBe(200.0)->and($redemption->currency)->toBe('INR')->and($this->points->earnedBalance($employee))->toBe(150);

    $run = $calculate(10);
    [$line] = $lines($run)->all();
    expect($line->code)->toBe('REWARD')->and($line->type)->toBe('earning')->and($line->taxable)->toBeTrue()->and((float) $line->amount)->toBe(300.0)
        ->and($line->basis['source'])->toEqual(['key' => 'reward_points', 'ids' => [$redemption->id, $own->id]])
        ->and(fn () => $this->points->cancelRedemption($own->refresh(), $this->hr, 'Too late'))->toThrow(RuntimeException::class, 'payroll being prepared')
        // A later month's run leaves the redemptions to the run that carries them.
        ->and($lines($calculate(11)))->toHaveCount(0);

    $runs->finalize($runs->approve($runs->validate($run), $approver, 'ok'), $approver);
    expect($redemption->refresh()->payroll_run_id)->toBe($run->id)->and($redemption->settled_at)->not->toBeNull()
        ->and($cancelled->refresh()->payroll_run_id)->toBeNull()
        ->and($lines($calculate(11)))->toHaveCount(0)
        ->and(fn () => $this->points->cancelRedemption($redemption->refresh(), $this->hr, 'Paid'))->toThrow(RuntimeException::class, 'not yet paid');
});
