<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Assets\Services\Assets;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseTaxType;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseDuplicateDetector;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Expense\Services\TravelRequests;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\AssetDesk;
use App\Filament\Pages\ExpenseDesk;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Resources\ExpenseAdvances\Pages\ListExpenseAdvances;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use App\Filament\Resources\ExpenseReceipts\Pages\ListExpenseReceipts;
use App\Filament\Resources\ExpenseReceipts\Pages\ViewExpenseReceipt;
use App\Filament\Resources\ExpenseReports\Pages\ListExpenseReports;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\TravelRequests\Pages\ListTravelRequests;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use App\Filament\Support\ExpenseRegisters;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne parity XO-1 (matrix 26: the Expense and Asset panels — desks in HROne's process order, travel and advance register
| depth, HROne's cancellation registers as views with refused cancellations kept, report links) and XO-2 (the expense
| receipt page and the receipt register's columns and filters; the duplicate check keeps the receipts it matched).
*/

beforeEach(function () {
    $this->travelTo('2026-06-10 10:00:00');
    Storage::fake('local');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create(['name' => 'North']);
    $this->south = Company::factory()->create(['name' => 'South']);
    [$this->managerUser, $this->manager] = xo12Person($this->tenant, $this->company, ['expense.claim', 'expense.approve']);
    [$this->user, $this->employee] = xo12Person($this->tenant, $this->company, ['expense.claim'], $this->manager);
    [$this->colleagueUser, $this->colleague] = xo12Person($this->tenant, $this->south, ['expense.claim'], $this->manager);
    $categories = app(ExpenseCategories::class);
    $this->hotel = $categories->save(null, ['type' => 'amount', 'name' => 'Hotel', 'code' => 'HOTEL', 'travel_link' => 'plan', 'travel_form' => 'accommodation', 'booked_by' => 'employee']);
    $this->stay = $categories->save(null, ['type' => 'amount', 'name' => 'Stay', 'code' => 'STAY', 'tax_applicable' => true, 'gst_supply_type' => 'services']);
    $this->cab = $categories->save(null, ['type' => 'amount', 'name' => 'Cab', 'code' => 'CAB']);
    $this->car = $categories->save(null, ['type' => 'mileage', 'name' => 'Car', 'code' => 'CAR', 'rate_per_km' => 10, 'location_input' => 'text']);
    xo12Policy('expense', ['categories' => [['category' => $this->hotel->id], ['category' => $this->stay->id], ['category' => $this->cab->id], ['category' => $this->car->id]]]);
    xo12Policy('expense_advance', ['enabled' => true, 'max_open' => 5]);
    $this->trips = app(TravelRequests::class);
    $this->advances = app(ExpenseAdvances::class);
    $this->receipts = app(ExpenseReceipts::class);
});

/** @return array{0: User, 1: Employee} */
function xo12Person(Tenant $tenant, Company $company, array $permissions, ?Employee $manager = null): array
{
    $user = tenantUser($tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);
    if ($manager !== null) {
        ReportingRelationship::query()->create(['employee_id' => $employee->id, 'manager_id' => $manager->id, 'type' => 'line', 'is_primary' => true, 'effective_from' => '2025-01-01']);
    }

    return [$user, $employee];
}

function xo12Policy(string $type, array $settings): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'XO12_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Everyone', 'conditions' => [], 'priority' => 1000]);

    return $policy;
}

/** A stay in Mumbai (booked by the employee, so an approved trip stays "approved"). */
function xo12Trip(Employee $employee, User $as, ExpenseCategory $hotel, string $from, string $to): TravelRequest
{
    return app(TravelRequests::class)->submit($employee, ['purpose' => 'Client visit', 'start_date' => $from, 'end_date' => $to,
        'legs' => [['expense_category_id' => $hotel->id, 'to_city' => 'Mumbai', 'to_state' => 'MH', 'starts_on' => $from, 'ends_on' => $to]]], $as);
}

function xo12File(string $content, string $name = 'bill.pdf'): string
{
    $path = ExpenseReceipts::directory().'/'.uniqid().'-'.$name;
    Storage::disk('local')->put($path, $content);

    return $path;
}

// ---- XO-1: refused cancellations, cancellation registers ----------------------------------------------------------

it('records a refused cancellation on the trip and the advance, through the existing approval and audit path', function () {
    $trip = xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-20', '2026-06-22');
    $this->trips->approve($trip, null, $this->managerUser);
    expect($trip->refresh()->cancel_refused_at)->toBeNull();
    expect($this->trips->cancel($trip->refresh(), 'Client postponed', $this->user)->status)->toBe('cancel_requested');
    $item = collect(app(ApprovalCenter::class)->pending($this->managerUser))->firstWhere('type', 'travel_cancellation');
    app(ApprovalDecisions::class)->decide($this->managerUser, $item->id, 'reject', 'Workshop is on');
    expect($trip->refresh()->status)->toBe('approved')->and($trip->cancel_requested_at)->toBeNull()
        ->and($trip->cancel_refused_at?->toDateTimeString())->toBe('2026-06-10 10:00:00')->and($trip->decision_note)->toBe('Workshop is on')
        ->and(AuditEvent::query()->where('entity_type', TravelRequest::class)->where('entity_id', $trip->id)->where('action', AuditAction::Rejected->value)->count())->toBe(1);

    // A later cancellation that is approved keeps the earlier refusal on record.
    $this->travelTo('2026-06-12 09:00:00');
    $this->trips->cancel($trip, 'Postponed again', $this->user);
    $this->trips->approveCancellation($trip->refresh(), null, $this->managerUser);
    expect($trip->refresh()->status)->toBe('cancelled')->and($trip->cancel_refused_at?->toDateString())->toBe('2026-06-10');

    $advance = $this->advances->approve($this->advances->request($this->employee, ['amount' => 500, 'purpose' => 'Trip'], $this->user), null, null, $this->managerUser);
    $this->advances->cancel($advance, 'Not travelling', $this->user);
    Livewire::test(ListExpenseAdvances::class)->callTableAction('keepAdvance', $advance->refresh(), ['note' => 'Trip is on'])->assertHasNoTableActionErrors();
    expect($advance->refresh()->status)->toBe('approved')->and($advance->cancel_refused_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('entity_type', ExpenseAdvance::class)->where('entity_id', $advance->id)->where('action', AuditAction::Rejected->value)->count())->toBe(1);
});

it('opens HROne\'s travel and advance cancellation registers as views: waiting, cancelled and refused', function () {
    $approve = fn (TravelRequest $t) => $this->trips->approve($t, null, $this->managerUser)->refresh();
    $waiting = $approve(xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-20', '2026-06-21'));
    $this->trips->cancel($waiting, 'Postponed', $this->user);
    $cancelled = $approve(xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-24', '2026-06-25'));
    $this->trips->cancel($cancelled, 'Postponed', $this->user);
    $this->trips->approveCancellation($cancelled->refresh(), null, $this->managerUser);
    $refused = $approve(xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-27', '2026-06-28'));
    $this->trips->cancel($refused, 'Postponed', $this->user);
    $this->trips->rejectCancellation($refused->refresh(), 'Needed', $this->managerUser);
    $plain = $approve(xo12Trip($this->employee, $this->user, $this->hotel, '2026-07-01', '2026-07-02'));

    Livewire::withQueryParams(['view' => 'cancellations'])->test(ListTravelRequests::class)
        ->assertCanSeeTableRecords([$waiting, $cancelled, $refused])->assertCanNotSeeTableRecords([$plain])
        ->assertCanRenderTableColumn('cancel_refused_at')
        ->filterTable('cancellation', ['refused'])->assertCanSeeTableRecords([$refused])->assertCanNotSeeTableRecords([$waiting, $cancelled, $plain]);
    Livewire::withQueryParams(['view' => 'cancellations_waiting'])->test(ListTravelRequests::class)
        ->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$cancelled, $refused, $plain]);

    $ask = fn (float $amount) => $this->advances->approve($this->advances->request($this->employee, ['amount' => $amount, 'purpose' => 'Trip'], $this->user), null, null, $this->managerUser);
    $a1 = $ask(100);
    $this->advances->cancel($a1, 'Off', $this->user);
    $a2 = $ask(200);
    $this->advances->cancel($a2, 'Off', $this->user);
    $this->advances->rejectCancellation($a2->refresh(), 'Keep it', $this->managerUser);
    $a3 = $ask(300);
    Livewire::withQueryParams(['view' => 'cancellations'])->test(ListExpenseAdvances::class)
        ->assertCanSeeTableRecords([$a1, $a2])->assertCanNotSeeTableRecords([$a3])
        ->assertCanRenderTableColumn('files');
    Livewire::withQueryParams(['view' => 'to_dispense'])->test(ListExpenseAdvances::class)
        ->assertCanSeeTableRecords([$a2, $a3])->assertCanNotSeeTableRecords([$a1]);
});

// ---- XO-1: register depth ------------------------------------------------------------------------------------------

it('shows requested, decided, decided by and whom a trip or advance waits with, for the HR side', function () {
    $decided = xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-20', '2026-06-21');
    $this->travelTo('2026-06-11 09:00:00');
    $this->trips->approve($decided, null, $this->managerUser);
    $center = xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-24', '2026-06-25');
    [$nodes, $edges] = linear([['id' => 'appr', 'type' => 'approval', 'name' => 'Travel head', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->managerUser->id]]]]);
    publishWorkflow($nodes, $edges, ['name' => 'Travel approval', 'key' => 'xo12_travel', 'trigger_event' => 'expense.travel_requested']);
    $flow = xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-27', '2026-06-28');
    $advance = $this->advances->request($this->employee, ['amount' => 100, 'purpose' => 'Trip'], $this->user);

    expect(ExpenseRegisters::tripWaitingWith($center->refresh()))->toBe('Expense approvers (Approval Center)')
        ->and(ExpenseRegisters::tripWaitingWith($flow->refresh()))->toBe($this->managerUser->name)
        ->and(ExpenseRegisters::tripWaitingWith($decided->refresh()))->toBeNull()
        ->and(ExpenseRegisters::advanceWaitingWith($advance))->toBe('Expense approvers (Approval Center)');

    Livewire::test(ListTravelRequests::class)
        ->assertCanNotRenderTableColumn('submitted_at')->assertCanNotRenderTableColumn('waiting_with')->assertCanNotRenderTableColumn('decider.name')
        ->assertTableColumnStateSet('decider.name', $this->managerUser->name, $decided)
        ->assertTableColumnStateSet('waiting_with', $this->managerUser->name, $flow);
    expect($decided->refresh()->decided_at?->toDateString())->toBe('2026-06-11');
    Livewire::withQueryParams(['view' => 'waiting'])->test(ListTravelRequests::class)
        ->assertCanSeeTableRecords([$center, $flow])->assertCanNotSeeTableRecords([$decided])
        ->assertCanRenderTableColumn('submitted_at')->assertCanRenderTableColumn('waiting_with')
        ->assertSee('Expense approvers (Approval Center)');
    Livewire::withQueryParams(['view' => 'waiting'])->test(ListExpenseAdvances::class)
        ->assertCanSeeTableRecords([$advance])->assertCanRenderTableColumn('waiting_with')->assertCanRenderTableColumn('files');
});

it('filters trips by requested, travel and decided dates, by employee and organisation, and advances by their dates', function () {
    $june = xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-20', '2026-06-25');
    $this->trips->approve($june, null, $this->managerUser);
    $this->travelTo('2026-06-15 09:00:00');
    $july = xo12Trip($this->colleague, $this->colleagueUser, $this->hotel, '2026-07-05', '2026-07-08');

    Livewire::test(ListTravelRequests::class)
        ->filterTable('request_dates', ['from' => '2026-06-12'])->assertCanSeeTableRecords([$july])->assertCanNotSeeTableRecords([$june])
        ->resetTableFilters()
        ->filterTable('decision_dates', ['from' => '2026-06-01'])->assertCanSeeTableRecords([$june])->assertCanNotSeeTableRecords([$july])
        ->resetTableFilters()
        // Travel date overlaps: a trip from 20 to 25 June is "travelling" on 24 June.
        ->filterTable('travel_dates', ['from' => '2026-06-24', 'until' => '2026-06-30'])->assertCanSeeTableRecords([$june])->assertCanNotSeeTableRecords([$july])
        ->resetTableFilters()
        ->filterTable('organisation', ['company_id' => [$this->south->id]])->assertCanSeeTableRecords([$july])->assertCanNotSeeTableRecords([$june])
        ->resetTableFilters()
        ->searchTable($this->colleague->employee_code)->assertCanSeeTableRecords([$july])->assertCanNotSeeTableRecords([$june]);

    $early = $this->advances->request($this->employee, ['amount' => 100, 'purpose' => 'A'], $this->user);
    $this->travelTo('2026-06-20 09:00:00');
    $late = $this->advances->request($this->colleague, ['amount' => 100, 'purpose' => 'B'], $this->colleagueUser);
    $this->advances->approve($late, null, null, $this->managerUser);
    Livewire::test(ListExpenseAdvances::class)
        ->filterTable('request_dates', ['until' => '2026-06-16'])->assertCanSeeTableRecords([$early])->assertCanNotSeeTableRecords([$late])
        ->resetTableFilters()
        ->filterTable('decision_dates', ['from' => '2026-06-20'])->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$early])
        ->resetTableFilters()
        ->searchTable($this->employee->employee_code)->assertCanSeeTableRecords([$early])->assertCanNotSeeTableRecords([$late]);
});

it('keeps an employee\'s own expense, advance and travel lists as they were', function () {
    $trip = xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-20', '2026-06-21');
    $other = xo12Trip($this->colleague, $this->colleagueUser, $this->hotel, '2026-06-20', '2026-06-21');
    $receipt = $this->receipts->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-06-09', 'amount' => 300], $this->user);
    $this->actingAs($this->user);

    Livewire::test(ListTravelRequests::class)
        ->assertCanSeeTableRecords([$trip])->assertCanNotSeeTableRecords([$other])
        ->assertTableColumnHidden('submitted_at')->assertTableColumnHidden('waiting_with')->assertTableColumnHidden('decider.name')
        ->assertTableFilterHidden('cancellation')->assertTableFilterHidden('travel_dates')->assertTableFilterHidden('request_dates')->assertTableFilterHidden('organisation')
        ->assertActionDoesNotExist('posSaveView')->assertActionDoesNotExist('registerReport')->assertActionVisible('requestTravel');
    Livewire::test(ListExpenseAdvances::class)
        ->assertTableColumnHidden('waiting_with')->assertTableFilterHidden('cancellation')->assertTableFilterHidden('request_dates')
        ->assertActionDoesNotExist('registerReport')->assertActionVisible('requestAdvance');
    Livewire::test(ListExpenseReceipts::class)
        ->assertCanSeeTableRecords([$receipt])
        ->assertTableColumnHidden('invoice_number')->assertTableColumnHidden('updated_at')->assertTableColumnHidden('payment')
        ->assertTableFilterHidden('type')->assertTableFilterHidden('checks')->assertTableFilterHidden('payment_status')->assertTableFilterHidden('expense_dates')
        ->assertActionDoesNotExist('registerReport')->assertActionVisible('addExpense');
    Livewire::test(ListExpenseReports::class)->assertActionDoesNotExist('registerReport')->assertActionVisible('newReport');
});

it('creates each register\'s report with its filters as the expense and travel datasets show them', function () {
    xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-20', '2026-06-21');

    Livewire::test(ListTravelRequests::class)
        ->filterTable('status', ['submitted'])
        ->filterTable('request_dates', ['from' => '2026-06-01'])
        ->filterTable('travel_dates', ['from' => '2026-06-15', 'until' => '2026-06-30'])
        ->callAction('registerReport', ['template' => 'travel_summary', 'with_filters' => true])
        ->assertHasNoActionErrors()->assertRedirect();
    $filters = collect(Report::query()->latest('id')->firstOrFail()->definition['filters']);
    expect($filters->firstWhere('field', 'status'))->toMatchArray(['operator' => 'in', 'value' => 'Waiting for approval'])
        ->and($filters->firstWhere('field', 'submitted_on'))->toMatchArray(['operator' => 'gte', 'value' => '2026-06-01'])
        ->and($filters->firstWhere('field', 'start_date'))->toMatchArray(['operator' => 'lte', 'value' => '2026-06-30'])
        ->and($filters->firstWhere('field', 'end_date'))->toMatchArray(['operator' => 'gte', 'value' => '2026-06-15']);

    Livewire::test(ListTravelRequests::class)->filterTable('status', ['submitted'])
        ->callAction('registerReport', ['template' => 'travel_details', 'with_filters' => true])->assertHasNoActionErrors();
    expect(collect(Report::query()->latest('id')->firstOrFail()->definition['filters'])->firstWhere('field', 'trip_status'))->toMatchArray(['value' => 'Waiting for approval']);

    Livewire::test(ListExpenseAdvances::class)->filterTable('status', ['requested'])
        ->callAction('registerReport', ['template' => 'expense_advance_amount', 'with_filters' => true])->assertHasNoActionErrors();
    expect(collect(Report::query()->latest('id')->firstOrFail()->definition['filters'])->firstWhere('field', 'status'))->toMatchArray(['value' => 'Requested']);

    Livewire::test(ListExpenseReceipts::class)->filterTable('type', 'mileage')->filterTable('expense_dates', ['until' => '2026-06-09'])
        ->callAction('registerReport', ['template' => 'expense_receipt_details', 'with_filters' => true])->assertHasNoActionErrors();
    $filters = collect(Report::query()->latest('id')->firstOrFail()->definition['filters']);
    expect($filters->firstWhere('field', 'type'))->toMatchArray(['value' => 'Mileage'])
        ->and($filters->firstWhere('field', 'expense_date'))->toMatchArray(['operator' => 'lte', 'value' => '2026-06-09']);

    Livewire::test(ListExpenseReports::class)->assertActionVisible('registerReport');
    Livewire::test(ListAssets::class)->assertActionVisible('registerReport');
    Livewire::test(ListTickets::class)->assertActionVisible('registerReport');
    $this->actingAs(tenantUser($this->tenant, ['servicedesk.request']));
    Livewire::test(ListTickets::class)->assertActionHidden('registerReport');
});

// ---- XO-1: desks ---------------------------------------------------------------------------------------------------

it('opens the expense desk for expense operators only, in HROne\'s process order, with its views and counts', function () {
    $trip = xo12Trip($this->employee, $this->user, $this->hotel, '2026-06-20', '2026-06-21');
    $this->trips->approve($trip, null, $this->managerUser);
    $this->trips->cancel($trip->refresh(), 'Off', $this->user);
    $this->advances->request($this->employee, ['amount' => 100, 'purpose' => 'Trip'], $this->user);

    $this->get(ExpenseDesk::getUrl())->assertOk()
        ->assertSee('Travel cancellations')->assertSee('Dispense advances')->assertSee('Pay expenses')
        ->assertSee(TravelRequestResource::getUrl('index', ['view' => 'cancellations']), false)
        ->assertSee(ExpenseAdvanceResource::getUrl('index', ['view' => 'cancellations']), false);
    $sections = collect((new ExpenseDesk)->deskSections());
    expect($sections->pluck('label')->all())->toBe(['Travel', 'Advances', 'Expense', 'Payments', 'Reports', 'Expense setup'])
        ->and(collect($sections->firstWhere('label', 'Travel')['items'])->firstWhere('label', 'Travel cancellations')['count'])->toBe(1)
        ->and(collect($sections->firstWhere('label', 'Advances')['items'])->firstWhere('label', 'Advance requests')['count'])->toBe(1)
        ->and(ExperienceNavigation::MODULE_AREA)->toHaveKey('ExpenseDesk')->toHaveKey('AssetDesk');

    // A manager approving the team's claims, or a read-only viewer, runs no expense desk.
    $this->actingAs($this->managerUser);
    expect(ExpenseDesk::canAccess())->toBeFalse();
    $this->get(ExpenseDesk::getUrl())->assertForbidden();
    $this->actingAs(tenantUser($this->tenant, ['expense.view']));
    expect(ExpenseDesk::canAccess())->toBeFalse();

    // The travel desk and finance get the desk, with only what they can open.
    $this->actingAs(tenantUser($this->tenant, ['expense.travel_desk']));
    expect(ExpenseDesk::canAccess())->toBeTrue();
    $labels = collect((new ExpenseDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label'));
    expect($labels)->toContain('Travel bookings')->not->toContain('Dispense advances')->not->toContain('Pay expenses');
    $this->actingAs(tenantUser($this->tenant, ['expense.view', 'expense.pay']));
    $labels = collect((new ExpenseDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label'));
    expect($labels)->toContain('Dispense advances')->toContain('Pay expenses')->toContain('Advance cancellations');
});

it('opens the asset desk for asset operators, with employee assets and acknowledgements as views of the register', function () {
    $assets = app(Assets::class);
    $laptops = AssetCategory::query()->where('code', 'LAPTOP')->firstOrFail();
    $signed = AssetSubtype::query()->create(['asset_category_id' => $laptops->id, 'name' => 'Signed series', 'code' => 'SIGN', 'acknowledgement_required' => true]);
    $receive = fn (string $tag) => $assets->receive(['asset_category_id' => $laptops->id, 'asset_subtype_id' => $signed->id, 'asset_tag' => $tag, 'name' => 'Laptop '.$tag, 'serial_number' => 'SN-'.$tag]);
    $stock = $receive('L-1');
    $pending = $receive('L-2');
    $assets->assign($pending, $this->employee, '2026-06-01');
    $done = $receive('L-3');
    $assets->acknowledge($assets->assign($done, $this->colleague, '2026-06-01'));

    $this->get(AssetDesk::getUrl())->assertOk()->assertSee('Assets by employee')->assertSee(AssetResource::getUrl('index', ['view' => 'unacknowledged']), false);
    $sections = collect((new AssetDesk)->deskSections());
    expect($sections->pluck('label')->all())->toBe(['Asset inventory', 'Employee assets', 'Asset acknowledgement', 'Asset request', 'Reports', 'Asset setup'])
        ->and(collect($sections->firstWhere('label', 'Asset acknowledgement')['items'])->first()['count'])->toBe(1)
        ->and(collect($sections->firstWhere('label', 'Employee assets')['items'])->first()['count'])->toBe(2);

    Livewire::withQueryParams(['view' => 'unacknowledged'])->test(ListAssets::class)->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$stock, $done]);
    Livewire::withQueryParams(['view' => 'assigned'])->test(ListAssets::class)->assertCanSeeTableRecords([$pending, $done])->assertCanNotSeeTableRecords([$stock]);
    Livewire::test(ListAssets::class)->filterTable('acknowledgement', 'acknowledged')->assertCanSeeTableRecords([$done])->assertCanNotSeeTableRecords([$stock, $pending]);

    $this->actingAs(tenantUser($this->tenant, ['asset.view', 'asset.assign']));
    expect(AssetDesk::canAccess())->toBeTrue();
    $this->actingAs(tenantUser($this->tenant, ['asset.view']));
    expect(AssetDesk::canAccess())->toBeFalse();
});

it('gives employees and requesters no asset desk and keeps My assets as it was', function () {
    $this->actingAs(tenantUser($this->tenant, ['asset.own', 'asset.request']));
    expect(AssetDesk::canAccess())->toBeFalse();
    Livewire::test(ListAssets::class)->assertTableFilterHidden('acknowledgement')->assertActionDoesNotExist('registerReport');
});

// ---- XO-2: the duplicate check keeps what it matched -------------------------------------------------------------

it('keeps the receipts the duplicate check matched without changing its verdicts', function () {
    $first = $this->receipts->capture($this->colleague, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-06-08', 'amount' => 450, 'merchant' => 'City Cabs', 'invoice_number' => 'inv-7'], $this->colleagueUser);
    $close = $this->receipts->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-06-08', 'amount' => 450, 'merchant' => 'city  cabs'], $this->user);
    expect($close->duplicate_score)->toBe(85)->and($close->flags)->toBe(['suspected_duplicate'])->and($close->duplicate_of_id)->toBe($first->id)
        ->and($close->duplicate_matches)->toBe([['id' => $first->id, 'score' => 85, 'reasons' => ['similar']]]);

    $invoice = $this->receipts->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-20', 'amount' => 77, 'merchant' => 'City Cabs', 'invoice_number' => 'INV-7'], $this->user);
    expect($invoice->flags)->toBe(['same_invoice_number'])->and($invoice->duplicate_of_id)->toBeNull()->and($invoice->duplicate_score)->toBe(30)
        ->and($invoice->duplicate_matches)->toBe([['id' => $first->id, 'score' => 30, 'reasons' => ['invoice']]]);

    // The detector's verdict fields are what they were; only `matches` is new.
    $verdict = app(ExpenseDuplicateDetector::class)->assess(['expense_date' => '2026-06-08', 'base_amount' => 450, 'merchant' => 'City Cabs', 'invoice_number' => null, 'attachment_hashes' => []], $close->id);
    expect(array_keys($verdict))->toBe(['refusal', 'score', 'of', 'flags', 'matches'])->and($verdict['of'])->toBe($first->id)->and($verdict['flags'])->toBe(['suspected_duplicate']);

    // A change re-checks: no longer alike, nothing kept.
    $this->receipts->update($close, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-06-08', 'amount' => 90, 'merchant' => 'Metro'], $this->user);
    expect($close->refresh()->duplicate_matches)->toBeNull()->and($close->flags)->toBeNull();

    // A plain receipt keeps nothing.
    expect($this->receipts->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-06-01', 'amount' => 12], $this->user)->duplicate_matches)->toBeNull();
});

// ---- XO-2: the receipt page ----------------------------------------------------------------------------------------

it('shows a receipt in full, read-only, to its owner and to HR in scope, and never to anyone else', function () {
    $cgst = ExpenseTaxType::query()->where('code', 'CGST')->firstOrFail();
    CustomField::query()->create(['entity' => 'expense_receipt', 'key' => 'cost_centre', 'label' => 'Cost centre', 'type' => 'text', 'status' => 'active']);
    $receipt = $this->receipts->capture($this->employee, ['expense_category_id' => $this->stay->id, 'expense_date' => '2026-06-08', 'amount' => 1180, 'merchant' => 'Sea View Hotel',
        'invoice_number' => 'HV-118', 'supplier_gstin' => '27aaacb1234c1z5', 'description' => 'Two nights near the client', 'attachments' => [xo12File('hotel-bill', 'hotel.pdf')],
        'taxes' => [['expense_tax_type_id' => $cgst->id, 'rate' => 9, 'amount' => 90]]], $this->user);
    $receipt->setCustomFields(['cost_centre' => 'CC-42']);

    $this->get(ExpenseReceiptResource::getUrl('view', ['record' => $receipt]))->assertOk()
        ->assertSee($receipt->number)->assertSee('Sea View Hotel')->assertSee('HV-118')->assertSee('27AAACB1234C1Z5')->assertSee($cgst->name)
        ->assertSee('Two nights near the client')->assertSee('CC-42')->assertSee('hotel.pdf')->assertSee('/files/expense-receipt/'.$receipt->id.'/0', false)->assertSee('Not in a report');
    expect(ExpenseReceiptResource::getRelations())->toContain(AuditHistoryRelationManager::class)
        ->and(AuditHistoryRelationManager::canViewForRecord($receipt, ViewExpenseReceipt::class))->toBeTrue();
    Livewire::test(ViewExpenseReceipt::class, ['record' => $receipt->getRouteKey()])->assertOk()->assertActionDoesNotExist('change')->assertActionDoesNotExist('withdraw');

    $mileage = $this->receipts->capture($this->employee, ['expense_category_id' => $this->car->id, 'expense_date' => '2026-06-09', 'distance_km' => 25, 'from_place' => 'Office', 'to_place' => 'Plant'], $this->user);
    $this->get(ExpenseReceiptResource::getUrl('view', ['record' => $mileage]))->assertOk()->assertSee('Mileage')->assertSee('Office')->assertSee('Plant')->assertSee('Rate per km')->assertSee('10.00');

    // The owner sees their own receipt (without the audit trail); a colleague and HR outside the scope do not.
    $this->actingAs($this->user);
    $this->get(ExpenseReceiptResource::getUrl('view', ['record' => $receipt]))->assertOk()->assertSee('Sea View Hotel');
    expect(AuditHistoryRelationManager::canViewForRecord($receipt, ViewExpenseReceipt::class))->toBeFalse();
    $this->actingAs($this->colleagueUser);
    expect($this->get(ExpenseReceiptResource::getUrl('view', ['record' => $receipt]))->status())->toBeIn([403, 404]);
    $scoped = tenantUser($this->tenant, ['expense.view']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'company', 'scope_id' => $this->south->id]);
    $this->actingAs($scoped);
    expect($this->get(ExpenseReceiptResource::getUrl('view', ['record' => $receipt]))->status())->toBeIn([403, 404]);
});

it('names the receipts a receipt resembles only where the viewer may open them, and the report\'s payment', function () {
    $first = $this->receipts->capture($this->colleague, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-06-08', 'amount' => 450, 'merchant' => 'City Cabs'], $this->colleagueUser);
    $close = $this->receipts->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-06-08', 'amount' => 450, 'merchant' => 'City Cabs'], $this->user);

    $matches = ExpenseRegisters::duplicateMatches($close);
    expect($matches['hidden'])->toBe(0)->and($matches['visible'])->toHaveCount(1)->and((string) $matches['visible'][0]['number'])->toContain($first->number)
        ->and($matches['visible'][0]['why'])->toBe('Similar amount, date or merchant (85%)');
    $this->get(ExpenseReceiptResource::getUrl('view', ['record' => $close]))->assertOk()->assertSee($first->number)
        ->assertSee(ExpenseReceiptResource::getUrl('view', ['record' => $first]), false);

    // HR of the employee's company only: the colleague's receipt is counted, not named.
    $north = tenantUser($this->tenant, ['expense.view']);
    UserAccessScope::query()->create(['user_id' => $north->id, 'dimension' => 'company', 'scope_id' => $this->company->id]);
    $this->actingAs($north);
    expect(ExpenseRegisters::duplicateMatches($close))->toMatchArray(['visible' => [], 'hidden' => 1]);
    $this->get(ExpenseReceiptResource::getUrl('view', ['record' => $close]))->assertOk()->assertDontSee($first->number)->assertSee('1 receipt(s) you cannot open');
    expect(app(DatasetRegistry::class)->get('expense_receipts')->value('resembles', $close->refresh()))->toBeNull();
    $this->actingAs($this->hr);
    expect(app(DatasetRegistry::class)->get('expense_receipts')->value('resembles', $close->refresh()))->toBe($first->number);

    // In an approved report waiting for payment; the employee sees the payment only when the organisation shows it.
    $reports = app(ExpenseReports::class);
    $report = $reports->submit($reports->create($this->employee, 'June', [$close->id], $this->user), $this->user);
    $reports->approvePart($report->parts()->sole(), null, null, $this->managerUser);
    $this->get(ExpenseReceiptResource::getUrl('view', ['record' => $close]))->assertOk()->assertSee($report->number)->assertSee('Waiting for payment');
    app(SettingsRepository::class)->set('expense.payment.show_to_employee', false, 'test');
    $this->actingAs($this->user);
    $this->get(ExpenseReceiptResource::getUrl('view', ['record' => $close]))->assertOk()->assertSee($report->number)->assertDontSee('Waiting for payment');
});

// ---- XO-2: the receipt register ------------------------------------------------------------------------------------

it('filters receipts by type, checks, payment status and expense date, with the invoice, reimbursable and updated columns', function () {
    $reports = app(ExpenseReports::class);
    $cab = $this->receipts->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-06-01', 'amount' => 300, 'merchant' => 'City Cabs', 'invoice_number' => 'C-1'], $this->user);
    $car = $this->receipts->capture($this->employee, ['expense_category_id' => $this->car->id, 'expense_date' => '2026-06-05', 'distance_km' => 20, 'from_place' => 'A', 'to_place' => 'B'], $this->user);
    $twin = $this->receipts->capture($this->colleague, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-06-01', 'amount' => 300, 'merchant' => 'City Cabs'], $this->colleagueUser);
    $paidReport = $reports->submit($reports->create($this->employee, 'Paid', [$cab->id], $this->user), $this->user);
    $reports->approvePart($paidReport->parts()->sole(), null, null, $this->managerUser);
    $reports->markPaid($paidReport->refresh(), 'UTR-1', '2026-06-10', $this->hr);
    $waitingReport = $reports->submit($reports->create($this->employee, 'Waiting', [$car->id], $this->user), $this->user);
    $reports->approvePart($waitingReport->parts()->sole(), null, null, $this->managerUser);

    Livewire::test(ListExpenseReceipts::class)
        ->assertTableColumnExists('invoice_number')->assertCanNotRenderTableColumn('invoice_number')->assertCanNotRenderTableColumn('reimbursable')->assertCanNotRenderTableColumn('updated_at')
        ->assertTableColumnStateSet('invoice_number', 'C-1', $cab)->assertTableColumnStateSet('payment', 'Paid outside payroll', $cab)
        ->assertTableActionVisible('view', $cab)
        ->filterTable('type', 'mileage')->assertCanSeeTableRecords([$car])->assertCanNotSeeTableRecords([$cab, $twin])
        ->resetTableFilters()
        ->filterTable('checks', ['suspected_duplicate'])->assertCanSeeTableRecords([$twin])->assertCanNotSeeTableRecords([$cab, $car])
        ->resetTableFilters()
        ->filterTable('payment_status', 'paid')->assertCanSeeTableRecords([$cab])->assertCanNotSeeTableRecords([$car, $twin])
        ->resetTableFilters()
        ->filterTable('payment_status', 'waiting')->assertCanSeeTableRecords([$car])->assertCanNotSeeTableRecords([$cab, $twin])
        ->resetTableFilters()
        ->filterTable('payment_status', 'nothing')->assertCanSeeTableRecords([$twin])->assertCanNotSeeTableRecords([$cab, $car])
        ->resetTableFilters()
        ->filterTable('expense_dates', ['from' => '2026-06-03'])->assertCanSeeTableRecords([$car])->assertCanNotSeeTableRecords([$cab, $twin])
        ->resetTableFilters()
        ->searchTable($this->colleague->employee_code)->assertCanSeeTableRecords([$twin])->assertCanNotSeeTableRecords([$cab, $car]);

    Livewire::withQueryParams(['view' => 'suspected'])->test(ListExpenseReceipts::class)
        ->assertCanSeeTableRecords([$twin])->assertCanNotSeeTableRecords([$cab, $car])->assertCanRenderTableColumn('invoice_number');
    Livewire::withQueryParams(['view' => 'awaiting_payment'])->test(ListExpenseReceipts::class)
        ->assertCanSeeTableRecords([$car])->assertCanNotSeeTableRecords([$cab, $twin])->assertCanRenderTableColumn('payment');
});
