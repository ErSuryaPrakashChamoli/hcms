<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Enterprise\Models\ExchangeRate;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseCategoryLimit;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Expense\Models\ExpenseLocationGroupPlace;
use App\Domain\Expense\Models\ExpenseTaxType;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseForms;
use App\Domain\Expense\Services\ExpenseLocationGroups;
use App\Domain\Identity\Models\Role;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Models\TenantSetting;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Filament\Pages\ExpenseFormSetup;
use App\Filament\Pages\SetupCentre;
use App\Filament\Resources\ExchangeRates\Pages\ManageExchangeRates;
use App\Filament\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Filament\Resources\ExpenseCategories\Pages\CreateExpenseCategory;
use App\Filament\Resources\ExpenseCategories\Pages\EditExpenseCategory;
use App\Filament\Resources\ExpenseGroups\ExpenseGroupResource;
use App\Filament\Resources\ExpenseLocationGroups\ExpenseLocationGroupResource;
use App\Filament\Resources\ExpenseLocationGroups\Pages\ManageExpenseLocationGroups;
use App\Filament\Resources\ExpenseTaxTypes\ExpenseTaxTypeResource;
use App\Filament\Resources\ExpenseTaxTypes\Pages\ManageExpenseTaxTypes;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Livewire\Livewire;

/* HROne setup parity EX-A — Expense foundation and masters: the module (capability, permissions, roles, navigation,
 * inventory, Setup centre), expense groups, categories with every HROne rule and their limits, location groups, tax
 * types (names only) and the expense and travel form matrices. */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
});

/** @param  array<string, mixed>  $overrides */
function expAmountCategory(array $overrides = []): ExpenseCategory
{
    return app(ExpenseCategories::class)->save(null, $overrides + ['type' => 'amount', 'name' => 'Meals '.Str::random(4), 'code' => 'MEAL_'.Str::upper(Str::random(5))]);
}

/** @param  list<array{state_code: string, cities?: list<string>}>  $places */
function expLocationGroup(string $code, array $places, string $status = 'active'): ExpenseLocationGroup
{
    return app(ExpenseLocationGroups::class)->save(null, ['name' => ucfirst(strtolower(str_replace('_', ' ', $code))), 'code' => $code, 'status' => $status], $places);
}

it('declares the expense module: capability, permissions, role templates, navigation, inventory and Setup centre', function () {
    expect(Capability::Expense->permissionPrefixes())->toBe(['expense'])
        ->and(Capability::Expense->commercial())->toBeTrue()
        ->and(array_keys(config('peopleos.permissions.expense')))->toBe(['expense.view', 'expense.manage', 'expense.claim', 'expense.approve', 'expense.pay', 'expense.travel_desk']);

    $keys = fn (string $slug) => Role::query()->where('slug', $slug)->firstOrFail()->permissions()->pluck('key')->filter(fn ($k) => str_starts_with($k, 'expense.'))->sort()->values()->all();
    expect($keys('employee'))->toBe(['expense.claim'])
        ->and($keys('manager'))->toBe(['expense.approve', 'expense.claim'])
        ->and($keys('payroll-admin'))->toBe(['expense.pay', 'expense.view'])
        ->and($keys('auditor'))->toBe(['expense.view'])
        ->and($keys('tenant-hr-admin'))->toHaveCount(6)
        ->and($keys('expense-admin'))->toHaveCount(6)
        ->and($keys('account-owner'))->toBe([]);

    foreach (['expense_groups', 'expense_categories', 'expense_category_limits', 'expense_location_groups', 'expense_location_group_places', 'expense_tax_types'] as $table) {
        expect(DataInventory::categoryOf($table))->toBe('expense');
    }
    expect(DataInventory::CATEGORIES['expense']['significance'])->toBe('financial')
        ->and(collect(DataInventory::prefixes($this->tenant)['expense'])->pluck('prefix')->all())->toBe(["tenants/{$this->tenant->id}/expense"]);

    expect(ExpenseCategoryResource::getNavigationGroup())->toBe('Expense & travel')
        ->and(collect(filament()->getPanel('admin')->getNavigationGroups())->map(fn ($g) => $g->getLabel())->all())->toContain('Expense & travel')
        ->and(config('peopleos_setup.modules.expense.policy_types'))->toBe(['expense', 'expense_advance'])
        ->and(config('peopleos.custom_fields.entities'))->toHaveKeys(['expense_receipt', 'travel_request']);
});

it('seeds starter groups and the country\'s tax types (names only), and lets an organisation add missing ones', function () {
    expect(ExpenseGroup::query()->orderBy('sort_order')->pluck('code')->all())->toBe(['TRAVEL', 'STAY', 'MEALS', 'CONVEYANCE', 'OTHER'])
        ->and(ExpenseTaxType::query()->orderBy('sort_order')->pluck('code')->all())->toBe(['CGST', 'SGST', 'UTGST', 'IGST'])
        ->and(ExpenseTaxType::query()->whereNotNull('default_rate')->count())->toBe(0);

    ExpenseTaxType::query()->where('code', 'UTGST')->delete();
    Livewire::test(ManageExpenseTaxTypes::class)->assertActionVisible('starterTaxes')->callAction('starterTaxes');
    expect(ExpenseTaxType::query()->pluck('code')->sort()->values()->all())->toBe(['CGST', 'IGST', 'SGST', 'UTGST']);

    // Another country: groups, and no Indian tax heads.
    actAsTenant(null);
    $uk = app(ProvisionTenantAction::class)->handle(['name' => 'UK Co', 'slug' => 'uk-co-'.Str::lower(Str::random(4)), 'country_code' => 'GB', 'currency' => 'GBP']);
    app(TenantContext::class)->runAs($uk, function () {
        expect(ExpenseGroup::query()->count())->toBe(5)->and(ExpenseTaxType::query()->count())->toBe(0);
    });
});

it('saves an amount category with every HROne rule, and its limits by grade and location group', function () {
    $senior = Grade::factory()->create(['code' => 'G9', 'name' => 'Senior']);
    $tier1 = expLocationGroup('TIER_1', [['state_code' => 'MH', 'cities' => ['Mumbai']]]);
    $service = ServiceDefinition::query()->create(['code' => 'TRAVEL_DESK', 'name' => 'Travel desk', 'ticket_category_id' => TicketCategory::query()->value('id'), 'status' => 'active']);
    $group = ExpenseGroup::query()->where('code', 'STAY')->firstOrFail();

    $category = app(ExpenseCategories::class)->save(null, [
        'type' => 'amount', 'name' => 'Daily allowance', 'code' => 'da', 'expense_group_id' => $group->id, 'description' => 'Per day on tour',
        'limit_basis' => 'amount', 'limit_dimension' => 'grade_id', 'limit_exception_allowed' => true,
        'limits' => [
            ['dimension_value_id' => $senior->id, 'expense_location_group_id' => $tier1->id, 'amount' => 3000, 'period' => 'day'],
            ['dimension_value_id' => $senior->id, 'expense_location_group_id' => null, 'amount' => 2000, 'period' => 'day'],
            ['dimension_value_id' => null, 'expense_location_group_id' => null, 'amount' => 1000, 'period' => 'day'],
        ],
        'hourly_constraints_enabled' => true, 'auto_fill_worked_hours' => true,
        'hourly_constraints' => [['from_minutes' => 720, 'to_minutes' => 1440, 'percent' => 100], ['from_minutes' => 240, 'to_minutes' => 720, 'percent' => 50]],
        'description_required' => true, 'receipt_required' => true, 'receipt_required_above' => 500, 'reimbursable' => true,
        'claim_frequency_period' => 'month', 'claim_frequency_count' => 20, 'tax_applicable' => true, 'gst_supply_type' => 'services',
        'travel_link' => 'plan', 'travel_form' => 'accommodation', 'booking_service_id' => $service->id, 'booked_by' => 'either',
        'before_joining_allowed' => false, 'from_location_required' => true,
        'attendance_rule' => ['mode' => 'min_hours', 'min_minutes' => 270],
        'submission_window_days' => ['from' => 0, 'to' => 30],
        'declaration_required' => true, 'declaration_text' => 'I was on official travel.',
        // Mileage-only answers on an amount category are dropped.
        'rate_per_km' => 9, 'odometer_required' => true, 'location_input' => 'text',
    ], 'New DA rules');

    expect($category->code)->toBe('DA')
        ->and($category->only(['type', 'limit_basis', 'limit_dimension', 'gst_supply_type', 'travel_link', 'travel_form', 'booked_by', 'claim_frequency_period', 'claim_frequency_count', 'declaration_text', 'location_input']))
        ->toBe(['type' => 'amount', 'limit_basis' => 'amount', 'limit_dimension' => 'grade_id', 'gst_supply_type' => 'services', 'travel_link' => 'plan', 'travel_form' => 'accommodation',
            'booked_by' => 'either', 'claim_frequency_period' => 'month', 'claim_frequency_count' => 20, 'declaration_text' => 'I was on official travel.', 'location_input' => 'none'])
        ->and($category->rate_per_km)->toBeNull()->and($category->odometer_required)->toBeFalse()
        ->and($category->hourly_constraints)->toBe([['from_minutes' => 240, 'to_minutes' => 720, 'percent' => 50], ['from_minutes' => 720, 'to_minutes' => 1440, 'percent' => 100]])
        ->and($category->attendance_rule)->toBe(['mode' => 'min_hours', 'min_minutes' => 270, 'on_duty' => null, 'on_duty_types' => []])
        ->and($category->submission_window_days)->toBe(['from' => 0, 'to' => 30])
        ->and((float) $category->receipt_required_above)->toBe(500.0)
        ->and($category->booking_service_id)->toBe($service->id)
        ->and($category->limits()->pluck('match_key')->all())->toEqualCanonicalizing(['|', "{$senior->id}|", "{$senior->id}|{$tier1->id}"]);

    // A change keeps unchanged rows, updates and adds and removes the others, each audited with the reason.
    app(ExpenseCategories::class)->save($category, ['limits' => [
        ['dimension_value_id' => $senior->id, 'expense_location_group_id' => $tier1->id, 'amount' => 3500, 'period' => 'day'],
        ['dimension_value_id' => null, 'expense_location_group_id' => $tier1->id, 'amount' => 1500, 'period' => 'day'],
        ['dimension_value_id' => null, 'expense_location_group_id' => null, 'amount' => 1000, 'period' => 'day'],
    ]], 'Tier 1 for all');
    $rows = $category->limits()->get()->mapWithKeys(fn (ExpenseCategoryLimit $l) => [$l->getAttributes()['match_key'] => (float) $l->amount])->sortKeys()->all();
    $expected = ['|' => 1000.0, "|{$tier1->id}" => 1500.0, "{$senior->id}|{$tier1->id}" => 3500.0];
    ksort($expected);
    expect($rows)->toBe($expected)
        ->and($category->refresh()->description)->toBe('Per day on tour')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('module', 'expense')->where('reason', 'Tier 1 for all')->count())->toBe(3);

    // A mileage category: rate, distance limits, odometer, location input; amount-only rules dropped.
    $bike = app(ExpenseCategories::class)->save(null, ['type' => 'mileage', 'name' => '2-wheeler', 'code' => 'BIKE', 'rate_per_km' => 4.5, 'odometer_required' => true,
        'location_input' => 'text', 'limit_basis' => 'km', 'limits' => [['km_limit' => 300, 'period' => 'month']],
        'tax_applicable' => true, 'gst_supply_type' => 'goods', 'hourly_constraints_enabled' => true, 'hourly_constraints' => [['from_minutes' => 0, 'to_minutes' => 60, 'percent' => 10]]]);
    expect((float) $bike->rate_per_km)->toBe(4.5)
        ->and($bike->only(['odometer_required', 'location_input', 'limit_basis', 'tax_applicable', 'gst_supply_type', 'hourly_constraints_enabled', 'hourly_constraints']))
        ->toBe(['odometer_required' => true, 'location_input' => 'text', 'limit_basis' => 'km', 'tax_applicable' => false, 'gst_supply_type' => null, 'hourly_constraints_enabled' => false, 'hourly_constraints' => null])
        ->and((float) $bike->limits()->sole()->km_limit)->toBe(300.0);
});

it('refuses a category that breaks HROne\'s rules', function () {
    $existing = expAmountCategory(['code' => 'TAXI']);
    $save = fn (array $data, ?ExpenseCategory $category = null) => app(ExpenseCategories::class)->save($category, $data);
    $amount = ['type' => 'amount', 'name' => 'X', 'code' => 'X1'];

    expect(fn () => $save(['type' => 'amount', 'name' => 'Cab', 'code' => 'taxi']))->toThrow(ExpenseRuleViolation::class, 'already uses the code TAXI')
        ->and(fn () => $save(['type' => 'mileage', 'name' => 'Car', 'code' => 'CAR']))->toThrow(ExpenseRuleViolation::class, 'rate per km')
        ->and(fn () => $save(['type' => 'mileage'], $existing))->toThrow(ExpenseRuleViolation::class, 'cannot change once the category exists')
        ->and(fn () => $save($amount + ['submission_window_days' => ['from' => 10, 'to' => 10]]))->toThrow(ExpenseRuleViolation::class, '"To Days" must be greater than "From Days"')
        ->and(fn () => $save($amount + ['hourly_constraints_enabled' => true, 'hourly_constraints' => [['from_minutes' => 0, 'to_minutes' => 300, 'percent' => 50], ['from_minutes' => 240, 'to_minutes' => 600, 'percent' => 100]]]))->toThrow(ExpenseRuleViolation::class, 'must not overlap')
        ->and(fn () => $save($amount + ['hourly_constraints_enabled' => true, 'hourly_constraints' => []]))->toThrow(ExpenseRuleViolation::class, 'at least one hourly band')
        ->and(fn () => $save($amount + ['limit_basis' => 'amount', 'limits' => [['amount' => 100], ['amount' => 200]]]))->toThrow(ExpenseRuleViolation::class, 'same employees and location group')
        ->and(fn () => $save($amount + ['limit_basis' => 'percent_of_ctc', 'limits' => [['percent' => 120]]]))->toThrow(ExpenseRuleViolation::class, 'at most 100')
        ->and(fn () => $save($amount + ['limit_basis' => 'amount', 'limits' => [['dimension_value_id' => 999999, 'amount' => 1]]]))->toThrow(ExpenseRuleViolation::class, 'employee dimension')
        ->and(fn () => $save($amount + ['limit_basis' => 'amount', 'limit_dimension' => 'grade_id', 'limits' => [['dimension_value_id' => 999999, 'amount' => 1]]]))->toThrow(ExpenseRuleViolation::class, 'grade that does not exist')
        ->and(fn () => $save($amount + ['limit_basis' => 'km']))->toThrow(ExpenseRuleViolation::class, 'limit type')
        ->and(fn () => $save($amount + ['claim_frequency_period' => 'month']))->toThrow(ExpenseRuleViolation::class, 'or neither')
        ->and(fn () => $save($amount + ['claim_frequency_period' => 'per_claim', 'claim_frequency_count' => 2]))->toThrow(ExpenseRuleViolation::class, 'claim frequency period')
        ->and(fn () => $save($amount + ['declaration_required' => true]))->toThrow(ExpenseRuleViolation::class, 'declaration')
        ->and(fn () => $save($amount + ['travel_link' => 'plan']))->toThrow(ExpenseRuleViolation::class, 'travel plan form')
        ->and(fn () => $save($amount + ['attendance_rule' => ['mode' => 'on_duty', 'on_duty' => 'specific', 'on_duty_types' => []]]))->toThrow(ExpenseRuleViolation::class, 'on-duty types')
        ->and(fn () => $save($amount + ['attendance_rule' => ['mode' => 'min_hours']]))->toThrow(ExpenseRuleViolation::class, 'minimum working hours');

    // The code is fixed once created, whatever path changes it.
    expect(fn () => $existing->update(['code' => 'CAB']))->toThrow(ExpenseRuleViolation::class, 'code cannot change');
    expect(ExpenseCategory::query()->count())->toBe(1);
});

it('keeps a place in one location group, and resolves a city before its state', function () {
    $tier1 = expLocationGroup('TIER_1', [['state_code' => 'MH', 'cities' => ['Mumbai', 'Pune']], ['state_code' => 'KA', 'cities' => ['Bengaluru']]]);
    $tier2 = expLocationGroup('TIER_2', [['state_code' => 'MH']]);
    expLocationGroup('PAUSED', [['state_code' => 'TN']], 'inactive');
    $groups = app(ExpenseLocationGroups::class);

    expect($groups->resolve(' mumbai ', 'mh')?->id)->toBe($tier1->id)
        ->and($groups->resolve('Nagpur', 'MH')?->id)->toBe($tier2->id)
        ->and($groups->resolve('Bengaluru', null)?->id)->toBe($tier1->id)
        ->and($groups->resolve('Chennai', 'TN'))->toBeNull()
        ->and($groups->resolve('Kochi', 'KL'))->toBeNull();

    expect(fn () => $groups->save($tier2, [], [['state_code' => 'MH'], ['state_code' => 'MH', 'cities' => ['Mumbai']]]))->toThrow(ExpenseRuleViolation::class, 'Mumbai (Maharashtra) is already in the location group Tier 1')
        ->and(fn () => expLocationGroup('TIER_3', [['state_code' => 'XX']]))->toThrow(ExpenseRuleViolation::class, 'Indian state');

    $groups->save($tier1, [], [['state_code' => 'MH', 'cities' => ['Mumbai']], ['state_code' => 'KA', 'cities' => ['Bengaluru']]], 'Pune moves');
    expect($groups->placesFor($tier1))->toBe([['country_code' => 'IN', 'state_code' => 'KA', 'cities' => ['Bengaluru']], ['country_code' => 'IN', 'state_code' => 'MH', 'cities' => ['Mumbai']]])
        ->and($groups->resolve('Pune', 'MH')?->id)->toBe($tier2->id)
        ->and(ExpenseLocationGroupPlace::query()->count())->toBe(4);

    // Outside India the state is typed in.
    $abroad = expLocationGroup('ABROAD', [['country_code' => 'US', 'state_code' => 'California', 'cities' => ['San Jose']]]);
    expect($groups->resolve('san jose', 'california', 'us')?->id)->toBe($abroad->id);
});

it('keeps the expense and travel form matrices as audited settings, with custom fields as Field one … ten', function () {
    CustomField::query()->create(['entity' => 'expense_receipt', 'key' => 'visit_code', 'label' => 'Visit code', 'type' => 'text', 'is_required' => true, 'status' => 'active']);
    CustomField::query()->create(['entity' => 'travel_request', 'key' => 'client', 'label' => 'Client', 'type' => 'text', 'status' => 'active']);
    $forms = app(ExpenseForms::class);

    expect(array_keys($forms->expenseFields()))->toBe(['purpose', 'location', 'participants', 'merchant', 'invoice_number', 'custom:visit_code'])
        ->and($forms->mandatory('custom:visit_code', 'mileage'))->toBeTrue()
        ->and($forms->visible('participants', 'mileage'))->toBeFalse()
        ->and(array_keys($forms->travelFields()))->toBe(['currency', 'estimated_cost', 'international_travel', 'visa_number', 'passport_number', 'attachment', 'custom:client']);

    $forms->saveExpense([
        ['key' => 'merchant', 'label' => 'Vendor', 'visible' => ['amount', 'travel'], 'mandatory' => ['amount']],
        ['key' => 'custom:visit_code', 'label' => 'ignored', 'visible' => ['amount'], 'mandatory' => ['amount']],
    ], 'Finance wants the vendor', $this->admin);
    $forms->saveTravel([['key' => 'visa_number', 'label' => '', 'visible' => true, 'mandatory' => true]], 'Visas', $this->admin);

    $fields = $forms->expenseFields();
    expect($fields['merchant'])->toMatchArray(['label' => 'Vendor', 'visible' => ['amount', 'travel'], 'mandatory' => ['amount']])
        ->and($fields['custom:visit_code']['label'])->toBe('Visit code')
        ->and($fields['purpose']['visible'])->toBe(['amount', 'mileage', 'accommodation', 'travel'])
        ->and($forms->missing('amount', ['merchant' => '', 'purpose' => 'Client visit']))->toBe(['Vendor', 'Visit code'])
        ->and($forms->missing('mileage', []))->toBe([])
        ->and($forms->travelMissing(['visa_number' => null]))->toBe(['Visa number'])
        ->and(TenantSetting::query()->where('key', 'expense.form.fields')->sole()->auditEvents()->where('reason', 'Finance wants the vendor')->exists())->toBeTrue();

    expect(fn () => $forms->saveExpense([['key' => 'purpose', 'visible' => ['amount'], 'mandatory' => ['mileage']]], 'x', $this->admin))->toThrow(ExpenseRuleViolation::class, 'mandatory only where it is shown')
        ->and(fn () => $forms->saveExpense([['key' => 'nonsense']], 'x', $this->admin))->toThrow(ExpenseRuleViolation::class, 'no field nonsense')
        ->and(fn () => $forms->saveTravel([['key' => 'currency', 'visible' => true]], ' ', $this->admin))->toThrow(ExpenseRuleViolation::class, 'reason')
        ->and(fn () => $forms->saveTravel([['key' => 'currency', 'visible' => true]], 'x', tenantUser($this->tenant, ['expense.view'])))->toThrow(ExpenseRuleViolation::class, 'cannot change');

    $page = Livewire::test(ExpenseFormSetup::class)->assertOk()->assertSee('Merchant')->assertSee('Visit code')->assertSee('Travel form');
    expect(collect($page->get('data.expense'))->firstWhere('key', 'merchant'))->toMatchArray(['label' => 'Vendor', 'mandatory' => ['amount']]);
    $page->set('data.audit_reason', 'Unchanged save')->call('save')->assertHasNoErrors();
    expect($forms->expenseFields()['merchant']['label'])->toBe('Vendor');
});

it('renders the expense screens, saves categories and location groups from them, and keeps them to expense.manage', function () {
    foreach ([ExpenseCategoryResource::class, ExpenseGroupResource::class, ExpenseLocationGroupResource::class, ExpenseTaxTypeResource::class] as $resource) {
        $this->get($resource::getUrl('index'))->assertOk();
    }
    $this->get(ExpenseCategoryResource::getUrl('create'))->assertOk();
    $this->get(ExpenseFormSetup::getUrl())->assertOk()->assertSee('Invoice number');

    $tier1 = expLocationGroup('TIER_1', [['state_code' => 'DL']]);
    Livewire::test(CreateExpenseCategory::class)
        ->fillForm(['type' => 'amount', 'name' => 'Hotel', 'code' => 'HOTEL', 'limit_basis' => 'amount', 'reimbursable' => true, 'travel_link' => 'none',
            'limits' => [['expense_location_group_id' => $tier1->id, 'amount' => 6000, 'period' => 'day'], ['expense_location_group_id' => null, 'amount' => 4000, 'period' => 'day']],
            'tenure_enabled' => true, 'tenure_from_days' => 0, 'tenure_to_days' => 45, 'status' => 'active'])
        ->call('create')->assertHasNoFormErrors();
    $hotel = ExpenseCategory::query()->where('code', 'HOTEL')->firstOrFail();
    expect($hotel->limits()->count())->toBe(2)->and($hotel->submission_window_days)->toBe(['from' => 0, 'to' => 45]);

    Livewire::test(EditExpenseCategory::class, ['record' => $hotel->id])->assertFormSet(['tenure_enabled' => true, 'tenure_to_days' => 45])
        ->fillForm(['name' => 'Hotel stay', 'audit_reason' => 'Rename'])->call('save')->assertHasNoFormErrors();
    expect($hotel->refresh()->name)->toBe('Hotel stay')->and($hotel->limits()->count())->toBe(2);

    Livewire::test(ManageExpenseLocationGroups::class)
        ->callAction('create', ['name' => 'Tier 2', 'code' => 'TIER_2', 'status' => 'active', 'places' => [['country_code' => 'IN', 'state_code' => 'GJ', 'cities' => ['Surat']]]])
        ->assertHasNoActionErrors();
    expect(app(ExpenseLocationGroups::class)->resolve('Surat', 'GJ')?->code)->toBe('TIER_2');

    Livewire::test(SetupCentre::class)->call('selectModule', 'expense')->assertOk()
        ->assertSee('Expense policy')->assertSee('Expense advance policy')->assertSee('Expense categories')->assertSee('Location groups')
        ->assertSee('Currency (exchange rates)')->assertSee('Do you want to allow exceptional expenses?')->assertSee('Exceptional expense');

    // Viewers read; only expense.manage changes; nobody else opens them.
    $this->actingAs(tenantUser($this->tenant, ['expense.view']));
    $this->get(ExpenseCategoryResource::getUrl('index'))->assertOk();
    $this->get(ExpenseCategoryResource::getUrl('create'))->assertForbidden();
    $this->get(ExpenseCategoryResource::getUrl('edit', ['record' => $hotel]))->assertForbidden();
    $this->get(ExpenseFormSetup::getUrl())->assertOk()->assertDontSee('Save forms');
    $this->actingAs(tenantUser($this->tenant, ['expense.claim']));
    $this->get(ExpenseCategoryResource::getUrl('index'))->assertForbidden();
    $this->get(ExpenseFormSetup::getUrl())->assertForbidden();
});

it('adds several exchange rates for one date, into the base currency (HROne currency policy)', function () {
    Livewire::test(ManageExchangeRates::class)
        ->callAction('severalRates', ['effective_on' => '2026-10-01', 'rates' => [['currency' => 'usd', 'rate' => 83.25], ['currency' => 'EUR', 'rate' => 90.5], ['currency' => 'INR', 'rate' => 1]]])
        ->assertHasNoActionErrors();

    expect(ExchangeRate::query()->orderBy('from_currency')->get()->map(fn (ExchangeRate $r) => [$r->from_currency, $r->to_currency, (float) $r->rate, $r->effective_on->toDateString()])->all())
        ->toBe([['EUR', 'INR', 90.5, '2026-10-01'], ['USD', 'INR', 83.25, '2026-10-01']])
        ->and(app(CurrencyRates::class)->toBase(10, 'USD', '2026-10-15'))->toBe(832.5);

    $this->actingAs(tenantUser($this->tenant, ['expense.manage']));
    Livewire::test(ManageExchangeRates::class)->assertForbidden();
});
