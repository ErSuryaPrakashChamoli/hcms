<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Contracts\ExpenseUsage;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseTaxType;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseForms;
use App\Domain\Expense\Services\ExpenseLocationGroups;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Support\ReceiptUsage;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/* HROne setup parity EX-C — expense receipts: every rule of an expense category and policy is enforced when an expense
 * is captured (policy, dates, filing window, expiry, form matrix, declaration, receipts, currency, mileage and odometer,
 * attendance, hourly bands, frequency, limits and exceptional expenses, taxes, duplicates), and the usage they leave
 * feeds later limits. */

beforeEach(function () {
    $this->travelTo('2026-05-20 10:00:00');
    Storage::fake('local');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = Company::factory()->create();
    [$this->managerUser, $this->manager] = excPerson($this->tenant, $this->company, ['expense.claim', 'expense.approve']);
    [$this->user, $this->employee] = excPerson($this->tenant, $this->company, ['expense.claim'], $this->manager);
    $this->meals = ExpenseGroup::query()->where('code', 'MEALS')->firstOrFail();
    $this->tier1 = app(ExpenseLocationGroups::class)->save(null, ['name' => 'Tier 1', 'code' => 'TIER_1'], [['state_code' => 'MH', 'cities' => ['Mumbai']]]);
    $this->food = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Food', 'code' => 'FOOD', 'expense_group_id' => $this->meals->id,
        'limit_basis' => 'amount', 'limit_exception_allowed' => true, 'receipt_required' => true, 'receipt_required_above' => 500,
        'limits' => [['expense_location_group_id' => $this->tier1->id, 'amount' => 1500, 'period' => 'day'], ['amount' => 1000, 'period' => 'day']]]);
    $this->cab = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Cab', 'code' => 'CAB']);
    $this->bike = app(ExpenseCategories::class)->save(null, ['type' => 'mileage', 'name' => 'Two-wheeler', 'code' => 'BIKE', 'rate_per_km' => 4, 'odometer_required' => true]);
    $this->policy = excPolicy('expense', ['categories' => [['category' => $this->food->id], ['category' => $this->cab->id], ['category' => $this->bike->id]]]);
    $this->receipts = app(ExpenseReceipts::class);
});

/** @return array{0: User, 1: Employee} a user with the permissions and their employee record */
function excPerson(Tenant $tenant, Company $company, array $permissions, ?Employee $manager = null, string $joined = '2025-01-01'): array
{
    $user = tenantUser($tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => $joined]);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => $joined]);
    if ($manager !== null) {
        ReportingRelationship::query()->create(['employee_id' => $employee->id, 'manager_id' => $manager->id, 'type' => 'line', 'is_primary' => true, 'effective_from' => $joined]);
    }

    return [$user, $employee];
}

/** @param  list<Employee>  $only */
function excPolicy(string $type, array $settings, array $only = []): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'EXC_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    $conditions = $only === [] ? [] : [['field' => 'employee_id', 'operator' => 'in', 'value' => array_map(fn (Employee $e) => $e->id, $only)]];
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Rule', 'conditions' => $conditions, 'priority' => $only === [] ? 1000 : 50]);

    return $policy;
}

/** A receipt file in the tenant's expense folder. */
function excFile(string $content, string $name = 'bill.pdf'): string
{
    $path = ExpenseReceipts::directory().'/'.uniqid().'-'.$name;
    Storage::disk('local')->put($path, $content);

    return $path;
}

function excSetting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function excCapture(Employee $employee, User $as, array $data): ExpenseReceipt
{
    return app(ExpenseReceipts::class)->capture($employee, $data, $as);
}

it('captures an expense in the employee\'s policy, converts other currencies at the expense date, and binds the receipts as usage', function () {
    expect(app(ExpenseUsage::class))->toBeInstanceOf(ReceiptUsage::class);

    $receipt = excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'amount' => 420, 'purpose' => 'Client visit']);
    expect($receipt->number)->toStartWith('RCP-2026-')->and($receipt->status)->toBe('unreported')->and((float) $receipt->base_amount)->toBe(420.0)
        ->and((float) $receipt->claimable_amount)->toBe(420.0)->and($receipt->currency)->toBe('INR')->and($receipt->policy_version_id)->not->toBeNull()
        ->and($receipt->reimbursable)->toBeTrue();

    expect(fn () => excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'currency' => 'USD', 'amount' => 10]))
        ->toThrow(ExpenseRuleViolation::class, 'There is no exchange rate from USD to INR on or before 18 May 2026');
    app(CurrencyRates::class)->set('USD', 'INR', 83.5, '2026-05-01');
    app(CurrencyRates::class)->set('USD', 'INR', 90, '2026-05-19');
    $usd = excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'currency' => 'usd', 'amount' => 10]);
    expect($usd->currency)->toBe('USD')->and((float) $usd->exchange_rate)->toBe(83.5)->and((float) $usd->base_amount)->toBe(835.0);

    expect(app(ExpenseUsage::class)->between($this->employee, $this->cab, now()->startOfMonth(), now()->endOfMonth()))->toBe(['amount' => 1255.0, 'km' => 0.0, 'count' => 2]);
});

it('refuses categories outside the policy, future dates, dates before joining and expenses outside the filing window', function () {
    $hotel = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Hotel', 'code' => 'HOTEL']);
    expect(fn () => excCapture($this->employee, $this->user, ['expense_category_id' => $hotel->id, 'expense_date' => '2026-05-18', 'amount' => 100]))->toThrow(ExpenseRuleViolation::class, 'Hotel is not in your expense policy.')
        ->and(fn () => excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-21', 'amount' => 100]))->toThrow(ExpenseRuleViolation::class, 'cannot be dated in the future');

    [$joinerUser, $joiner] = excPerson($this->tenant, $this->company, ['expense.claim'], null, '2026-05-15');
    expect(fn () => excCapture($joiner, $joinerUser, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-10', 'amount' => 100]))
        ->toThrow(ExpenseRuleViolation::class, 'Cab cannot be claimed for a date before your date of joining (15 May 2026).');
    app(ExpenseCategories::class)->save($this->cab, ['before_joining_allowed' => true, 'submission_window_days' => ['from' => 0, 'to' => 7]]);
    expect(excCapture($joiner, $joinerUser, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-14', 'amount' => 100])->status)->toBe('unreported')
        ->and(fn () => excCapture($joiner, $joinerUser, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-10', 'amount' => 100]))
        ->toThrow(ExpenseRuleViolation::class, 'Cab can be filed between 0 and 7 days after the expense date; an expense of 10 May 2026 is 10 day(s) old.');

    // No expense policy at all.
    [$otherUser, $other] = excPerson($this->tenant, $this->company, ['expense.claim']);
    PolicyAssignmentRule::query()->where('policy_id', $this->policy->id)->update(['conditions' => [['field' => 'employee_id', 'operator' => 'in', 'value' => [$this->employee->id]]]]);
    expect(fn () => excCapture($other, $otherUser, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'amount' => 100]))->toThrow(ExpenseRuleViolation::class, 'No expense policy applies to you on 18 May 2026');
});

it('enforces the expense form matrix, the description, the declaration and a receipt above the category threshold', function () {
    app(ExpenseForms::class)->saveExpense([['key' => 'merchant', 'label' => 'Vendor', 'visible' => ['amount'], 'mandatory' => ['amount']], ['key' => 'participants', 'visible' => [], 'mandatory' => []]], 'test', $this->admin);
    CustomField::query()->create(['entity' => 'expense_receipt', 'key' => 'cost_centre_code', 'label' => 'Cost centre code', 'type' => 'text', 'status' => 'active']);
    app(ExpenseForms::class)->saveExpense([['key' => 'custom:cost_centre_code', 'visible' => ['amount'], 'mandatory' => ['amount']]], 'test', $this->admin);
    app(ExpenseCategories::class)->save($this->cab, ['description_required' => true, 'declaration_required' => true, 'declaration_text' => 'I travelled on company work.']);

    $base = ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'amount' => 300];
    expect(fn () => excCapture($this->employee, $this->user, $base))->toThrow(ExpenseRuleViolation::class, 'Fill in Vendor, Cost centre code.')
        ->and(fn () => excCapture($this->employee, $this->user, $base + ['merchant' => 'City Cabs', 'custom' => ['cost_centre_code' => 'CC1']]))->toThrow(ExpenseRuleViolation::class, 'Describe the expense')
        ->and(fn () => excCapture($this->employee, $this->user, $base + ['merchant' => 'City Cabs', 'custom' => ['cost_centre_code' => 'CC1'], 'description' => 'Airport']))->toThrow(ExpenseRuleViolation::class, 'Accept the declaration: I travelled on company work.');
    $receipt = excCapture($this->employee, $this->user, $base + ['merchant' => 'City Cabs', 'custom' => ['cost_centre_code' => 'CC1'], 'description' => 'Airport', 'declaration_accepted' => true, 'participants' => ['A colleague']]);
    expect($receipt->declaration_accepted_at)->not->toBeNull()->and($receipt->merchant)->toBe('City Cabs')->and($receipt->participants)->toBeNull()
        ->and($receipt->customFields())->toBe(['cost_centre_code' => 'CC1']);

    // Food needs a receipt above 500.
    expect(excCapture($this->employee, $this->user, ['expense_category_id' => $this->food->id, 'expense_date' => '2026-05-18', 'amount' => 400, 'merchant' => 'Cafe', 'custom' => ['cost_centre_code' => 'CC1']])->status)->toBe('unreported')
        ->and(fn () => excCapture($this->employee, $this->user, ['expense_category_id' => $this->food->id, 'expense_date' => '2026-05-17', 'amount' => 600, 'merchant' => 'Cafe', 'custom' => ['cost_centre_code' => 'CC1']]))
        ->toThrow(ExpenseRuleViolation::class, 'Attach the receipt: Food needs one above 500.00.');
    $withFile = excCapture($this->employee, $this->user, ['expense_category_id' => $this->food->id, 'expense_date' => '2026-05-17', 'amount' => 600, 'merchant' => 'Cafe', 'custom' => ['cost_centre_code' => 'CC1'], 'attachments' => [excFile('bill-600')]]);
    expect($withFile->attachments)->toHaveCount(1)->and($withFile->attachment_hashes)->toBe([hash('sha256', 'bill-600')]);
    expect(fn () => excCapture($this->employee, $this->user, ['expense_category_id' => $this->food->id, 'expense_date' => '2026-05-16', 'amount' => 600, 'merchant' => 'Cafe', 'custom' => ['cost_centre_code' => 'CC1'], 'attachments' => ['tenants/999/expense/x.pdf']]))
        ->toThrow(ExpenseRuleViolation::class, 'could not be found');
});

it('caps a claim over its limit, or files it as an exceptional expense with a reason, and counts earlier claims of the period', function () {
    $food = fn (array $extra) => excCapture($this->employee, $this->user, ['expense_category_id' => $this->food->id, 'expense_date' => '2026-05-18', 'merchant' => 'Cafe'] + $extra);

    $first = $food(['amount' => 400, 'location_city' => 'Mumbai', 'location_state' => 'MH']);
    expect($first->expense_location_group_id)->toBe($this->tier1->id)->and((float) $first->limit_amount)->toBe(1500.0)->and((float) $first->claimable_amount)->toBe(400.0);

    // 1,100 left in Mumbai today: 1,300 is capped (exceptional expenses are off).
    $capped = $food(['amount' => 1300, 'location_city' => 'Mumbai', 'location_state' => 'MH', 'attachments' => [excFile('b1')]]);
    expect((float) $capped->claimable_amount)->toBe(1100.0)->and((float) $capped->over_limit_amount)->toBe(200.0)->and($capped->flags)->toBe(['over_limit'])->and($capped->is_exceptional)->toBeFalse();

    excSetting('expense.exceptional.enabled', true);
    $exceptional = $food(['amount' => 900, 'location_city' => 'Mumbai', 'location_state' => 'MH', 'attachments' => [excFile('b2')], 'exceptional_reason' => 'Client dinner']);
    expect($exceptional->is_exceptional)->toBeTrue()->and($exceptional->exception_reason)->toBe('Client dinner')->and((float) $exceptional->claimable_amount)->toBe(900.0)
        ->and((float) $exceptional->over_limit_amount)->toBe(900.0);

    // Without a reason the claim is capped (nothing is left today).
    expect((float) $food(['amount' => 100, 'location_city' => 'Mumbai', 'location_state' => 'MH'])->claimable_amount)->toBe(0.0);

    // Exceptional-only categories need a reason.
    excPolicy('expense', ['categories' => [['category' => $this->cab->id, 'exceptional_only' => true]]], [$this->employee]);
    expect(fn () => excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'amount' => 100]))->toThrow(ExpenseRuleViolation::class, 'Cab is claimed only as an exceptional expense: give the reason.')
        ->and(excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'amount' => 100, 'exceptional_reason' => 'Night shift'])->is_exceptional)->toBeTrue();
});

it('enforces the claim frequency and the policy\'s limit across all categories', function () {
    app(ExpenseCategories::class)->save($this->cab, ['claim_frequency_period' => 'week', 'claim_frequency_count' => 2]);
    excPolicy('expense', ['categories' => [['category' => $this->cab->id], ['category' => $this->food->id]], 'overall_limit_enabled' => true, 'overall_limit_amount' => 1000, 'overall_limit_period' => 'month'], [$this->employee]);
    $cab = fn (string $date, float $amount) => excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => $date, 'amount' => $amount]);

    $cab('2026-05-18', 300);
    $cab('2026-05-19', 300);
    expect(fn () => $cab('2026-05-20', 300))->toThrow(ExpenseRuleViolation::class, 'Cab can be claimed 2 time(s) weekly (monday to sunday), and that many are already claimed between 2026-05-18 and 2026-05-24.');
    // A new week; only 400 of the monthly 1,000 is left across categories.
    $capped = $cab('2026-05-11', 600);
    expect((float) $capped->claimable_amount)->toBe(400.0)->and($capped->flags)->toBe(['over_limit']);
    // A withdrawn expense frees its frequency and amount.
    app(ExpenseReceipts::class)->withdraw($capped, 'Wrong week', $this->user);
    expect((float) $cab('2026-05-12', 300)->claimable_amount)->toBe(300.0);
});

it('works out mileage from the odometer, with two photos, and allows editing the distance only where the settings do', function () {
    $bike = fn (array $extra) => excCapture($this->employee, $this->user, ['expense_category_id' => $this->bike->id, 'expense_date' => '2026-05-18'] + $extra);
    expect(fn () => $bike(['odometer_start' => 100, 'odometer_end' => 90]))->toThrow(ExpenseRuleViolation::class, 'the end above the start')
        ->and(fn () => $bike(['odometer_start' => 100, 'odometer_end' => 130, 'attachments' => [excFile('o1')]]))->toThrow(ExpenseRuleViolation::class, 'Attach the two odometer photos');

    $trip = $bike(['odometer_start' => 100, 'odometer_end' => 130, 'attachments' => [excFile('o1'), excFile('o2')]]);
    expect((float) $trip->distance_km)->toBe(30.0)->and($trip->distance_source)->toBe('odometer')->and((float) $trip->amount)->toBe(120.0)->and($trip->type)->toBe('mileage');

    $edited = $bike(['odometer_start' => 200, 'odometer_end' => 230, 'distance_km' => 35, 'attachments' => [excFile('o3'), excFile('o4')]]);
    expect((float) $edited->distance_km)->toBe(35.0)->and((float) $edited->computed_distance_km)->toBe(30.0)->and($edited->flags)->toBe(['distance_edited']);
    excSetting('expense.mileage.distance_editable', false);
    expect(fn () => $bike(['odometer_start' => 300, 'odometer_end' => 330, 'distance_km' => 35, 'attachments' => [excFile('o5'), excFile('o6')]]))
        ->toThrow(ExpenseRuleViolation::class, 'The distance comes from the odometer (30 km) and cannot be changed.');

    // A map distance (supplied by a maps provider) may be edited only within the tolerance; beyond it the claim is exceptional.
    $car = app(ExpenseCategories::class)->save(null, ['type' => 'mileage', 'name' => 'Car', 'code' => 'CAR', 'rate_per_km' => 10, 'location_input' => 'text']);
    excPolicy('expense', ['categories' => [['category' => $car->id]]], [$this->employee]);
    excSetting('expense.mileage.distance_editable', true);
    $map = fn (array $extra) => excCapture($this->employee, $this->user, ['expense_category_id' => $car->id, 'expense_date' => '2026-05-18', 'distance_source' => 'map', 'computed_distance_km' => 100, 'from_place' => 'Office', 'to_place' => 'Plant'] + $extra);
    expect(fn () => $map(['distance_km' => 105]))->toThrow(ExpenseRuleViolation::class, 'The distance calculated on the map (100 km) cannot be changed.');
    excSetting('expense.mileage.map_edit_allowed', true);
    expect((float) $map(['distance_km' => 108])->amount)->toBe(1080.0)
        ->and(fn () => $map(['distance_km' => 120]))->toThrow(ExpenseRuleViolation::class, 'The distance is more than 10% above the map\'s 100 km.');
    excSetting('expense.exceptional.enabled', true);
    expect($map(['distance_km' => 120, 'exceptional_reason' => 'Diversion'])->is_exceptional)->toBeTrue()
        ->and(fn () => excCapture($this->employee, $this->user, ['expense_category_id' => $car->id, 'expense_date' => '2026-05-18', 'distance_km' => 10]))->toThrow(ExpenseRuleViolation::class, 'Give the places you travelled from and to.');
});

it('checks attendance (present, minimum time, on duty) and pays a daily allowance by its hourly band, hours auto-filled from attendance', function () {
    $da = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Daily allowance', 'code' => 'DA', 'limit_basis' => 'amount',
        'hourly_constraints_enabled' => true, 'hourly_constraints' => [['from_minutes' => 240, 'to_minutes' => 480, 'percent' => 50], ['from_minutes' => 480, 'to_minutes' => 1441, 'percent' => 100]],
        'auto_fill_worked_hours' => true, 'attendance_rule' => ['mode' => 'present'], 'limits' => [['amount' => 800, 'period' => 'day']]]);
    excPolicy('expense', ['categories' => [['category' => $da->id]]], [$this->employee]);
    $claim = fn (string $date, array $extra = []) => excCapture($this->employee, $this->user, ['expense_category_id' => $da->id, 'expense_date' => $date, 'amount' => 800] + $extra);

    expect(fn () => $claim('2026-05-18'))->toThrow(ExpenseRuleViolation::class, 'Give the hours worked on 18 May 2026');
    AttendanceRecord::create(['employee_id' => $this->employee->id, 'date' => '2026-05-18', 'status' => 'absent', 'worked_minutes' => 0, 'processed_at' => now()]);
    expect(fn () => $claim('2026-05-18'))->toThrow(ExpenseRuleViolation::class, 'Daily allowance can be claimed only for a day you were present');
    AttendanceRecord::create(['employee_id' => $this->employee->id, 'date' => '2026-05-15', 'status' => 'present', 'worked_minutes' => 300, 'processed_at' => now()]);
    $half = $claim('2026-05-15', ['worked_minutes' => 600]);
    expect($half->worked_minutes)->toBe(300)->and((float) $half->claimable_amount)->toBe(400.0)->and($half->flags)->toBe(['over_limit']);

    app(ExpenseCategories::class)->save($da, ['attendance_rule' => ['mode' => 'min_hours', 'min_minutes' => 360]]);
    expect(fn () => $claim('2026-05-15'))->toThrow(ExpenseRuleViolation::class, 'needs at least 6h 00m worked that day; your attendance on 15 May 2026 shows 5h 00m.');

    app(ExpenseCategories::class)->save($da, ['auto_fill_worked_hours' => false, 'attendance_rule' => ['mode' => 'on_duty', 'on_duty' => 'specific', 'on_duty_types' => ['Client visit']]]);
    expect(fn () => $claim('2026-05-14', ['worked_minutes' => 500]))->toThrow(ExpenseRuleViolation::class, 'on duty (Client visit) and 14 May 2026 is not one');
    TimeOfficeRequest::query()->create(['employee_id' => $this->employee->id, 'type' => 'on_duty', 'status' => 'approved', 'from_date' => '2026-05-13', 'to_date' => '2026-05-14', 'category' => 'Client visit', 'reason' => 'Visit']);
    expect((float) $claim('2026-05-14', ['worked_minutes' => 500])->claimable_amount)->toBe(800.0);
});

it('records the taxes printed on a receipt and the supplier GSTIN', function () {
    $hotel = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Hotel', 'code' => 'HOTEL', 'tax_applicable' => true, 'gst_supply_type' => 'services']);
    excPolicy('expense', ['categories' => [['category' => $hotel->id]]], [$this->employee]);
    $cgst = ExpenseTaxType::query()->where('code', 'CGST')->firstOrFail();
    $sgst = ExpenseTaxType::query()->where('code', 'SGST')->firstOrFail();
    $base = ['expense_category_id' => $hotel->id, 'expense_date' => '2026-05-18', 'amount' => 1180, 'merchant' => 'Hotel'];

    expect(fn () => excCapture($this->employee, $this->user, $base + ['taxes' => [['expense_tax_type_id' => $cgst->id, 'amount' => 1000], ['expense_tax_type_id' => $sgst->id, 'amount' => 500]]]))->toThrow(ExpenseRuleViolation::class, 'cannot add up to more than the receipt amount')
        ->and(fn () => excCapture($this->employee, $this->user, $base + ['supplier_gstin' => 'BAD']))->toThrow(ExpenseRuleViolation::class, 'A GST registration number has 15 letters and digits.');
    $receipt = excCapture($this->employee, $this->user, $base + ['supplier_gstin' => '27aaacb1234c1z5', 'taxes' => [['expense_tax_type_id' => $cgst->id, 'rate' => 9, 'amount' => 90], ['expense_tax_type_id' => $sgst->id, 'rate' => 9, 'amount' => 90]]]);
    expect($receipt->supplier_gstin)->toBe('27AAACB1234C1Z5')->and($receipt->gst_supply_type)->toBe('services')
        ->and($receipt->taxes()->with('taxType')->get()->map(fn ($t) => [$t->taxType->code, (float) $t->amount])->all())->toBe([['CGST', 90.0], ['SGST', 90.0]]);
});

it('refuses a receipt file claimed before, flags close matches and applies the duplicate invoice rule', function () {
    [$colleagueUser, $colleague] = excPerson($this->tenant, $this->company, ['expense.claim']);
    $file = excFile('same-bill');
    excCapture($colleague, $colleagueUser, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'amount' => 450, 'merchant' => 'City Cabs', 'invoice_number' => 'inv-7', 'attachments' => [$file]]);

    expect(fn () => excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-10', 'amount' => 90, 'attachments' => [excFile('same-bill')]]))
        ->toThrow(ExpenseRuleViolation::class, 'This receipt file was already claimed (RCP-');

    $close = excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'amount' => 450, 'merchant' => 'city  cabs']);
    expect($close->duplicate_score)->toBe(85)->and($close->flags)->toBe(['suspected_duplicate'])->and($close->duplicate_of_id)->not->toBeNull();
    excSetting('expense.duplicates.threshold_percent', 90);
    expect(excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-17', 'amount' => 450, 'merchant' => 'City Cabs'])->flags)->toBeNull();

    // The same invoice number from the same merchant: flagged by default, refused or allowed by the setting.
    expect(excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-01', 'amount' => 77, 'merchant' => 'City Cabs', 'invoice_number' => 'INV-7'])->flags)->toBe(['same_invoice_number']);
    excSetting('expense.duplicates.same_invoice_number', 'refuse');
    expect(fn () => excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-02', 'amount' => 66, 'merchant' => 'City Cabs', 'invoice_number' => ' inv-7 ']))
        ->toThrow(ExpenseRuleViolation::class, 'Invoice INV-7 from City Cabs was already claimed')
        ->and(excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-02', 'amount' => 66, 'merchant' => 'Other Cabs', 'invoice_number' => 'INV-7'])->flags)->toBeNull();

    excSetting('expense.duplicates.check', false);
    expect(excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-03', 'amount' => 55, 'attachments' => [excFile('same-bill')]])->status)->toBe('unreported');
});

it('expires unreported expenses past the expiry period (daily command) and refuses expired dates', function () {
    excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-04-01', 'amount' => 100]);
    $recent = excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-15', 'amount' => 100]);
    $this->artisan('peopleos:expense:expire', ['--tenant' => $this->tenant->slug])->assertSuccessful();
    expect(ExpenseReceipt::query()->where('status', 'expired')->count())->toBe(0);

    excSetting('expense.expiry.period', 30);
    expect(app(ExpenseReceipts::class)->hasExpired($recent))->toBeFalse();
    $this->artisan('peopleos:expense:expire', ['--tenant' => $this->tenant->slug])->assertSuccessful()->expectsOutputToContain('1 expense(s) expired');
    expect(ExpenseReceipt::query()->where('status', 'expired')->pluck('expense_date')->map->toDateString()->all())->toBe(['2026-04-01'])
        ->and(fn () => excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-04-10', 'amount' => 100]))
        ->toThrow(ExpenseRuleViolation::class, 'Expenses of 10 Apr 2026 expired on 10 May 2026');

    excSetting('expense.expiry.based_on', 'month_end');
    excSetting('expense.expiry.unit', 'months');
    excSetting('expense.expiry.period', 1);
    expect(excCapture($this->employee, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-04-10', 'amount' => 100])->expires_on->toDateString())->toBe('2026-05-30');
});

it('keeps employees to their own expenses, lets approvers file on behalf in scope, and changes or withdraws only undecided expenses', function () {
    [$colleagueUser, $colleague] = excPerson($this->tenant, $this->company, ['expense.claim']);
    expect(fn () => excCapture($colleague, $this->user, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'amount' => 100]))->toThrow(ExpenseRuleViolation::class, 'You cannot file expenses for this employee.');
    $onBehalf = excCapture($this->employee, $this->managerUser, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'amount' => 100]);
    expect($onBehalf->created_by)->toBe($this->managerUser->id);
    $noClaim = tenantUser($this->tenant, ['expense.view']);
    $noClaimEmployee = Employee::factory()->create(['user_id' => $noClaim->id]);
    expect(fn () => excCapture($noClaimEmployee, $noClaim, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-18', 'amount' => 100]))->toThrow(ExpenseRuleViolation::class, 'You cannot file expenses');

    $changed = app(ExpenseReceipts::class)->update($onBehalf, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-17', 'amount' => 150], $this->user);
    expect((float) $changed->amount)->toBe(150.0)->and($changed->number)->toBe($onBehalf->number);
    expect(fn () => app(ExpenseReceipts::class)->withdraw($changed, '', $this->user))->toThrow(ExpenseRuleViolation::class, 'Give the reason');
    app(ExpenseReceipts::class)->withdraw($changed, 'Duplicate', $this->user);
    expect($changed->refresh()->status)->toBe('withdrawn')
        ->and(fn () => app(ExpenseReceipts::class)->update($changed, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-17', 'amount' => 10], $this->user))->toThrow(ExpenseRuleViolation::class, 'can no longer be changed');
});
