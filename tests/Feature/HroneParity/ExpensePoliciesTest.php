<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicySetup;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Expense\Contracts\ExpenseUsage;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseLimits;
use App\Domain\Expense\Services\ExpenseLocationGroups;
use App\Domain\Expense\Services\ExpensePolicies;
use App\Domain\Expense\Support\ExpenseAdvanceRules;
use App\Domain\Expense\Support\ExpensePeriods;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\PolicyWizard;
use App\Filament\Resources\Policies\Pages\ListPolicies;
use App\Filament\Support\PolicySettingsSchema;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Livewire\Livewire;

/* HROne setup parity EX-B — Expense policies: the `expense` policy type (categories with overrides, exceptional-only,
 * overall limit) and `expense_advance`, and the ExpenseLimits resolver (policy override → category limit by dimension
 * and location group → period usage; percentage of CTC through the compensation contract). */

beforeEach(function () {
    $this->travelTo('2026-05-10 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = Company::factory()->create();
    $this->senior = Grade::factory()->create(['code' => 'G9', 'name' => 'Senior']);
    $this->tier1 = app(ExpenseLocationGroups::class)->save(null, ['name' => 'Tier 1', 'code' => 'TIER_1'], [['state_code' => 'MH', 'cities' => ['Mumbai']]]);
    $this->meals = ExpenseGroup::query()->where('code', 'MEALS')->firstOrFail();
    $this->da = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Daily allowance', 'code' => 'DA', 'expense_group_id' => $this->meals->id,
        'limit_basis' => 'amount', 'limit_dimension' => 'grade_id', 'limit_exception_allowed' => true, 'receipt_required' => true, 'receipt_required_above' => 500,
        'claim_frequency_period' => 'month', 'claim_frequency_count' => 20,
        'limits' => [
            ['dimension_value_id' => $this->senior->id, 'expense_location_group_id' => $this->tier1->id, 'amount' => 3000, 'period' => 'day'],
            ['dimension_value_id' => $this->senior->id, 'amount' => 2000, 'period' => 'day'],
            ['expense_location_group_id' => $this->tier1->id, 'amount' => 1500, 'period' => 'day'],
            ['amount' => 1000, 'period' => 'day'],
        ]]);
    $this->cab = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Late-night cab', 'code' => 'CAB']);
    $this->seniorEmployee = expEmployee($this->company, $this->senior);
    $this->juniorEmployee = expEmployee($this->company);
});

function expEmployee(Company $company, ?Grade $grade = null): Employee
{
    $employee = Employee::factory()->create(['joining_date' => '2024-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'grade_id' => $grade?->id, 'effective_from' => '2024-01-01']);

    return $employee;
}

/**
 * @param  array<string, mixed>  $settings
 * @param  list<Employee>  $only  named employees (empty = everyone)
 */
function expPolicy(string $type, array $settings, array $only = []): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'EXP_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    $conditions = $only === [] ? [] : [['field' => 'employee_id', 'operator' => 'in', 'value' => array_map(fn (Employee $e) => $e->id, $only)]];
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Rule', 'conditions' => $conditions, 'priority' => $only === [] ? 1000 : 50]);

    return $policy;
}

/** A fake usage: fixed totals per category code (null = every category), recording each window asked for. */
function expFakeUsage(array $totals): object
{
    $usage = new class($totals) implements ExpenseUsage
    {
        /** @var list<array{0: ?string, 1: string, 2: string}> */
        public array $asked = [];

        public function __construct(private array $totals) {}

        public function between(Employee $employee, ?ExpenseCategory $category, CarbonInterface $from, CarbonInterface $to, ?int $exceptReceiptId = null): array
        {
            $this->asked[] = [$category?->code, $from->toDateString(), $to->toDateString()];

            return $this->totals[$category?->code ?? '*'] ?? ['amount' => 0.0, 'km' => 0.0, 'count' => 0];
        }
    };
    app()->instance(ExpenseUsage::class, $usage);

    return $usage;
}

it('declares the expense and expense advance policy types with HROne-style wizard steps and expense options', function () {
    expect(array_column(PolicySettingsSchema::steps('expense'), 'label'))->toBe(['Expense categories', 'Limit across all categories'])
        ->and(array_column(PolicySettingsSchema::steps('expense_advance'), 'label'))->toBe(['Advance requests', 'Payout', 'Settlement and recovery'])
        ->and(PolicySettingsSchema::display(['type' => 'select', 'options_from' => 'expense_categories'], (string) $this->da->id))->toBe('Daily allowance · Amount · Meals')
        ->and(PolicySettingsSchema::display(['type' => 'select', 'options_from' => 'expense_location_groups'], $this->tier1->id))->toBe('Tier 1')
        ->and(PolicySettingsSchema::display(['type' => 'multiselect', 'options_from' => 'expense_groups'], [$this->meals->id]))->toBe('Meals')
        ->and(PolicySettingsSchema::display(['type' => 'select', 'options_from' => 'expense_periods'], 'half_year'))->toBe('Half-yearly')
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'multiselect', 'options_from' => 'expense_categories']))->toContain('Daily allowance', 'Late-night cab')
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'multiselect', 'options_from' => 'expense_groups']))->toContain('Meals')
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'select', 'options_from' => 'expense_location_groups']))->toBe([$this->tier1->id => 'Tier 1'])
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'select', 'options_from' => 'expense_periods']))->toHaveKey('trip');

    $summary = PolicySettingsSchema::summary('expense', ['categories' => [['category' => $this->da->id], ['category' => $this->cab->id]], 'overall_limit_enabled' => false, 'overall_limit_amount' => 5]);
    expect($summary[0]['rows'][0])->toBe(['Expense categories', 'Daily allowance · Amount · Meals, Late-night cab · Amount'])
        ->and(collect($summary[1]['rows'])->pluck(0)->all())->toBe(['Do you want to apply limit across all categories?']);

    $wizard = Livewire::withQueryParams(['type' => 'expense'])->test(PolicyWizard::class)->assertOk();
    expect(array_column($wizard->instance()->steps(), 'label'))->toContain('Expense categories', 'Limit across all categories', 'Applicability');

    $policy = expPolicy('expense', ['categories' => [['category' => $this->da->id], ['category' => $this->cab->id]]]);
    Livewire::withQueryParams(['filters' => ['type' => ['value' => 'expense']]])->test(ListPolicies::class)
        ->assertCanSeeTableRecords([$policy])->assertTableColumnStateSet('expense_categories', 2, $policy);
    Livewire::withQueryParams([])->test(ListPolicies::class)->assertTableColumnHidden('expense_categories');
    expect(collect(app(SetupCatalogue::class)->policyTypes('expense'))->firstWhere('type', 'expense'))->toMatchArray(['label' => 'Expense policy', 'policies' => 1])
        ->and(urldecode((string) collect(app(SetupCatalogue::class)->policyTypes('expense'))->firstWhere('type', 'expense_advance')['url']))->toContain('filters[type][value]=expense_advance');
});

it('offers only the categories of the employee\'s expense policy, and nothing without one', function () {
    $limits = app(ExpenseLimits::class);
    expect(app(ExpensePolicies::class)->categoriesFor($this->seniorEmployee, '2026-05-10'))->toBeEmpty()
        ->and($limits->for($this->seniorEmployee, $this->da, '2026-05-10')->refusal)->toContain('No expense policy applies to you on 10 May 2026');

    expPolicy('expense', ['categories' => [['category' => (string) $this->da->id], ['category' => $this->cab->id, 'exceptional_only' => true]]]);
    $hotel = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Hotel', 'code' => 'HOTEL']);
    expect(app(ExpensePolicies::class)->categoriesFor($this->seniorEmployee, '2026-05-10')->pluck('code')->all())->toBe(['DA'])
        ->and($limits->for($this->seniorEmployee, $this->cab, '2026-05-10')->refusal)->toContain('only as an exceptional expense, and exceptional expenses are switched off')
        ->and($limits->for($this->seniorEmployee, $hotel, '2026-05-10')->refusal)->toBe('Hotel is not in your expense policy.')
        ->and($limits->for($this->seniorEmployee, $this->da, '2026-05-10')->allowed)->toBeTrue();

    app(SettingsRepository::class)->set('expense.exceptional.enabled', true, 'Exceptional expenses on');
    $cab = $limits->for($this->seniorEmployee, $this->cab, '2026-05-10');
    expect(app(ExpensePolicies::class)->categoriesFor($this->seniorEmployee, '2026-05-10')->pluck('code')->sort()->values()->all())->toBe(['CAB', 'DA'])
        ->and($cab->allowed)->toBeTrue()->and($cab->exceptionalOnly)->toBeTrue()->and($cab->limit)->toBeNull();

    app(ExpenseCategories::class)->save($this->da, ['status' => 'inactive']);
    expect(app(ExpensePolicies::class)->categoriesFor($this->seniorEmployee, '2026-05-10')->pluck('code')->all())->toBe(['CAB'])
        ->and($limits->for($this->seniorEmployee, $this->da->refresh(), '2026-05-10')->refusal)->toContain('no longer in use');
});

it('resolves the limit: the policy override for the location group, the policy amount, then the category row by grade and location group', function () {
    $limits = app(ExpenseLimits::class);
    $special = expEmployee($this->company, $this->senior);
    expPolicy('expense', ['categories' => [['category' => $this->da->id]]]);
    expPolicy('expense', ['categories' => [['category' => $this->da->id, 'limit_amount' => 2500, 'limit_period' => 'month',
        'location_limits' => [['location_group' => (string) $this->tier1->id, 'amount' => 5000]]]]], [$special]);
    $mumbai = app(ExpenseLocationGroups::class)->resolve('Mumbai', 'MH');
    $pick = fn (Employee $e, ?ExpenseLocationGroup $g) => (fn ($l) => [$l->limit, $l->source, $l->period])($limits->for($e, $this->da, '2026-05-10', $g));

    expect($pick($this->seniorEmployee, $mumbai))->toBe([3000.0, 'category', 'day'])
        ->and($pick($this->seniorEmployee, null))->toBe([2000.0, 'category', 'day'])
        ->and($pick($this->juniorEmployee, $mumbai))->toBe([1500.0, 'category', 'day'])
        ->and($pick($this->juniorEmployee, null))->toBe([1000.0, 'category', 'day'])
        ->and($pick($special, $mumbai))->toBe([5000.0, 'policy_location', 'month'])
        ->and($pick($special, null))->toBe([2500.0, 'policy', 'month']);

    // The row picked is the category's own: a category without limits has none.
    expect($limits->bestRow($this->juniorEmployee, $this->da, now(), $mumbai)?->getAttributes()['match_key'])->toBe("|{$this->tier1->id}");
    $free = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Stationery', 'code' => 'STAT']);
    expPolicy('expense', ['categories' => [['category' => $free->id]]], [$this->juniorEmployee]);
    $result = $limits->for($this->juniorEmployee, $free, '2026-05-10');
    expect($result->allowed)->toBeTrue()->and($result->limit)->toBeNull()->and($result->remaining)->toBeNull()->and($result->headroom())->toBeNull();
});

it('counts the period used, the claim frequency and the policy\'s overall limit through the usage contract', function () {
    app(SettingsRepository::class)->set('expense.year_start_month', 4, 'Financial year');
    expect(ExpensePeriods::window('per_claim', '2026-05-10'))->toBeNull()
        ->and(array_map(fn ($d) => $d->toDateString(), ExpensePeriods::window('week', '2026-05-10')))->toBe(['2026-05-04', '2026-05-10'])
        ->and(array_map(fn ($d) => $d->toDateString(), ExpensePeriods::window('month', '2026-02-10')))->toBe(['2026-02-01', '2026-02-28'])
        ->and(array_map(fn ($d) => $d->toDateString(), ExpensePeriods::window('quarter', '2026-05-10', 4)))->toBe(['2026-04-01', '2026-06-30'])
        ->and(array_map(fn ($d) => $d->toDateString(), ExpensePeriods::window('half_year', '2026-11-03', 4)))->toBe(['2026-10-01', '2027-03-31'])
        ->and(array_map(fn ($d) => $d->toDateString(), ExpensePeriods::window('year', '2026-02-10', 4)))->toBe(['2025-04-01', '2026-03-31'])
        ->and(array_map(fn ($d) => $d->toDateString(), ExpensePeriods::window('year', '2026-02-10', 1)))->toBe(['2026-01-01', '2026-12-31'])
        ->and(ExpensePeriods::window('trip', '2026-05-10'))->toBeNull()
        ->and(array_map(fn ($d) => $d->toDateString(), ExpensePeriods::window('trip', '2026-05-10', 4, ['2026-05-08', '2026-05-12'])))->toBe(['2026-05-08', '2026-05-12']);

    expPolicy('expense', ['categories' => [['category' => $this->da->id, 'limit_period' => 'year', 'claim_frequency_count' => 3]],
        'overall_limit_enabled' => '1', 'overall_limit_amount' => 50000, 'overall_limit_period' => 'quarter']);
    $usage = expFakeUsage(['DA' => ['amount' => 1200.0, 'km' => 0.0, 'count' => 2], '*' => ['amount' => 49500.0, 'km' => 0.0, 'count' => 9]]);
    $limit = app(ExpenseLimits::class)->for($this->juniorEmployee, $this->da, '2026-05-10', null, ['except_receipt_id' => 7]);

    expect([$limit->limit, $limit->period, $limit->window, $limit->used, $limit->remaining])->toBe([1000.0, 'year', ['2026-04-01', '2027-03-31'], 1200.0, 0.0])
        ->and($limit->frequency)->toBe(['period' => 'month', 'count' => 3, 'used' => 2, 'remaining' => 1, 'window' => ['2026-05-01', '2026-05-31']])
        ->and($limit->overall)->toBe(['amount' => 50000.0, 'period' => 'quarter', 'used' => 49500.0, 'remaining' => 500.0, 'window' => ['2026-04-01', '2026-06-30']])
        ->and($limit->headroom())->toBe(0.0)
        ->and($usage->asked)->toBe([['DA', '2026-04-01', '2027-03-31'], ['DA', '2026-05-01', '2026-05-31'], [null, '2026-04-01', '2026-06-30']]);

    // Per claim: nothing to count against, the whole limit is open (the overall one still counts its quarter).
    expPolicy('expense', ['categories' => [['category' => $this->da->id, 'limit_period' => 'per_claim']]], [$this->seniorEmployee]);
    $usage = expFakeUsage(['DA' => ['amount' => 99999.0, 'km' => 0.0, 'count' => 99]]);
    $perClaim = app(ExpenseLimits::class)->for($this->seniorEmployee, $this->da, '2026-05-10');
    expect([$perClaim->limit, $perClaim->window, $perClaim->remaining, $perClaim->overall])->toBe([2000.0, null, 2000.0, null])
        ->and($perClaim->frequency['remaining'])->toBe(0);
});

it('takes a percentage-of-CTC limit from the approved compensation, of the total CTC or one component', function () {
    $basic = SalaryComponent::query()->where('code', 'BASIC')->firstOrFail();
    $conv = SalaryComponent::query()->where('code', 'CONV')->firstOrFail();
    $phone = fn (?SalaryComponent $component, string $code) => app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => "Phone {$code}", 'code' => $code,
        'limit_basis' => 'percent_of_ctc', 'ctc_component_id' => $component?->id, 'limits' => [['percent' => 10, 'period' => 'year']]]);
    $ofCtc = $phone(null, 'PHONE_CTC');
    $ofBasic = $phone($basic, 'PHONE_BASIC');
    $ofConv = $phone($conv, 'PHONE_CONV');
    expPolicy('expense', ['categories' => [['category' => $ofCtc->id], ['category' => $ofBasic->id], ['category' => $ofConv->id]]]);
    compensate($this->juniorEmployee, 600000, '2026-01-01', ['CONV' => 1600]);
    $limits = app(ExpenseLimits::class);

    expect($limits->for($this->juniorEmployee, $ofCtc, '2026-05-10')->limit)->toBe(60000.0)
        ->and($limits->for($this->juniorEmployee, $ofBasic, '2026-05-10')->limit)->toBe(24000.0)    // Basic = 40% of CTC (a formula)
        ->and($limits->for($this->juniorEmployee, $ofConv, '2026-05-10')->limit)->toBe(1920.0)      // 1,600 a month, fixed
        ->and($limits->for($this->juniorEmployee, $ofCtc, '2026-05-10')->basis)->toBe('percent_of_ctc');

    // No compensation in force: no money (fail closed), and it says why.
    $none = $limits->for($this->seniorEmployee, $ofCtc, '2026-05-10');
    expect([$none->limit, $none->compensationMissing])->toBe([0.0, true])
        ->and($limits->for($this->juniorEmployee, $ofCtc, '2025-12-31')->compensationMissing)->toBeTrue();
});

it('applies hourly bands, mileage rates, over-limit handling and receipt thresholds', function () {
    app(ExpenseCategories::class)->save($this->da, ['hourly_constraints_enabled' => true, 'hourly_constraints' => [
        ['from_minutes' => 240, 'to_minutes' => 720, 'percent' => 50], ['from_minutes' => 720, 'to_minutes' => 1440, 'percent' => 100]]]);
    $bike = app(ExpenseCategories::class)->save(null, ['type' => 'mileage', 'name' => '2-wheeler', 'code' => 'BIKE', 'rate_per_km' => 4.5, 'limit_basis' => 'km',
        'limits' => [['km_limit' => 300, 'period' => 'month']]]);
    expPolicy('expense', ['categories' => [['category' => $this->da->id], ['category' => $bike->id]]]);
    $limits = app(ExpenseLimits::class);
    $da = fn (array $context = []) => $limits->for($this->juniorEmployee, $this->da->refresh(), '2026-05-10', null, $context);

    expect([$da(['minutes' => 300])->limit, $da(['minutes' => 300])->hourlyPercent])->toBe([500.0, 50.0])
        ->and($da(['minutes' => 800])->limit)->toBe(1000.0)
        ->and($da(['minutes' => 120])->limit)->toBe(0.0)
        ->and($da()->limit)->toBe(1000.0);

    $km = $limits->for($this->juniorEmployee, $bike, '2026-05-10');
    expect([$km->unit, $km->limit, $km->amountLimit, $km->basis])->toBe(['km', 300.0, 1350.0, 'km']);

    // Over the limit: capped until exceptional expenses are on and the category (or the policy) allows filing it as one.
    expect($da()->overLimit)->toBe('cap');
    app(SettingsRepository::class)->set('expense.exceptional.enabled', true, 'On');
    expect($da()->overLimit)->toBe('exceptional')
        ->and($da()->needsReceipt(400))->toBeFalse()->and($da()->needsReceipt(600))->toBeTrue();

    expPolicy('expense', ['categories' => [['category' => $this->da->id, 'limit_exception_allowed' => 'no', 'receipt_above' => 1000], ['category' => $bike->id, 'km_limit' => 100]]], [$this->seniorEmployee]);
    $senior = $limits->for($this->seniorEmployee, $this->da, '2026-05-10');
    $seniorKm = $limits->for($this->seniorEmployee, $bike, '2026-05-10');
    expect($senior->overLimit)->toBe('cap')->and($senior->needsReceipt(600))->toBeFalse()->and($senior->needsReceipt(1200))->toBeTrue()
        ->and([$seniorKm->limit, $seniorKm->amountLimit, $seniorKm->source])->toBe([100.0, 450.0, 'policy']);
});

it('reads the expense advance policy with HROne\'s defaults, and refuses advances without one', function () {
    $rules = fn (Employee $e) => app(ExpensePolicies::class)->advanceRules($e, '2026-05-10');
    expect($rules($this->juniorEmployee)->refusal())->toBe(ExpenseAdvanceRules::NO_POLICY);

    expPolicy('expense_advance', ['enabled' => false]);
    expect($rules($this->juniorEmployee)->refusal())->toBe('Your expense advance policy does not allow advance requests.');

    expPolicy('expense_advance', ['enabled' => '1', 'max_amount' => 10000], [$this->juniorEmployee]);
    $junior = $rules($this->juniorEmployee);
    expect($junior->refusal())->toBeNull()
        ->and($junior->refusalFor(12000))->toContain('at most 10,000.00')
        ->and($junior->refusalFor(8000))->toBeNull()
        ->and([$junior->maxOpen, $junior->maxOutstandingTotal, $junior->settleWithinDays, $junior->recovery, $junior->recoveryAfterDays, $junior->disbursement, $junior->cancellationNeedsApproval, $junior->purposeRequired])
        ->toBe([1, null, 30, 'payroll', 15, 'bank', true, false]);

    expPolicy('expense_advance', ['enabled' => true, 'max_open' => 2, 'max_outstanding_total' => 25000, 'purpose_required' => '1', 'attachment_required' => 0,
        'cancellation_needs_approval' => false, 'disbursement' => 'payroll', 'settle_within_days' => 10, 'recovery' => 'exit_only'], [$this->seniorEmployee]);
    $senior = $rules($this->seniorEmployee);
    expect([$senior->maxOpen, $senior->maxOutstandingTotal, $senior->purposeRequired, $senior->attachmentRequired, $senior->cancellationNeedsApproval, $senior->disbursement, $senior->settleWithinDays, $senior->recovery])
        ->toBe([2, 25000.0, true, false, false, 'payroll', 10, 'exit_only']);
});

it('saves an expense policy through the policy wizard\'s service, keeping its categories and overall limit', function () {
    $policy = app(PolicySetup::class)->save(['type' => 'expense', 'name' => 'Field sales expenses', 'applies_to' => 'everyone', 'effective_from' => '2026-04-01',
        'settings' => ['categories' => [['category' => (string) $this->da->id, 'limit_amount' => 1800, 'exceptional_only' => false, 'location_limits' => []]],
            'overall_limit_enabled' => true, 'overall_limit_amount' => 20000, 'overall_limit_period' => 'month', 'stray' => 'dropped']], null, $this->admin, true, 'Field sales');

    $version = $policy->versions()->first();
    expect($version->settings)->toHaveKeys(['categories', 'overall_limit_enabled', 'overall_limit_amount', 'overall_limit_period'])->not->toHaveKey('stray');
    $limit = app(ExpenseLimits::class)->for($this->juniorEmployee, $this->da, '2026-05-10');
    expect([$limit->limit, $limit->source, $limit->overall['amount'], $limit->overall['window']])->toBe([1800.0, 'policy', 20000.0, ['2026-05-01', '2026-05-31']]);

    // Saving needs policy rights; viewing the masters is not enough.
    expect(fn () => app(PolicySetup::class)->save(['type' => 'expense', 'name' => 'Other'], null, tenantUser($this->tenant, ['expense.manage', 'policy.view']), false, 'x'))
        ->toThrow(RuntimeException::class, 'cannot create policies');
});
