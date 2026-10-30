<?php

use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReportTravel;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Models\TravelRequestLeg;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseForms;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Expense\Services\TravelBookings;
use App\Domain\Expense\Services\TravelRequests;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\AttendanceCalendar;
use App\Filament\Resources\TravelRequests\Pages\ListTravelRequests;
use App\Filament\Resources\TravelRequests\Pages\ViewTravelRequest;
use App\Filament\Resources\TravelRequests\RelationManagers\LegsRelationManager;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../ServiceDesk/ServiceDeskTestHelpers.php';

/* HROne setup parity EX-F — travel requests: the travel form matrix, backdated / overlap / multi-leg / mixed-category
 * rules, the advance decided with the trip, approval (Approval Center and workflows), company bookings raised as helpdesk
 * requests and recorded by the travel desk, cancellation with approval, expenses and reports linked to trips with
 * per-trip limits, the attendance calendar mark, travel datasets and HROne's four travel templates. */

beforeEach(function () {
    $this->travelTo('2026-06-10 10:00:00');
    Storage::fake('local');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = Company::factory()->create();
    [$this->managerUser, $this->manager] = trvPerson($this->tenant, $this->company, ['expense.claim', 'expense.approve']);
    [$this->user, $this->employee] = trvPerson($this->tenant, $this->company, ['expense.claim', 'attendance.regularise'], $this->manager);
    $this->desk = tenantUser($this->tenant, ['expense.travel_desk']);

    $this->bookingService = sdApprovedService('AIR_BOOKING');
    $categories = app(ExpenseCategories::class);
    $this->air = $categories->save(null, ['type' => 'amount', 'name' => 'Air ticket', 'code' => 'AIR', 'travel_link' => 'plan', 'travel_form' => 'travel',
        'booked_by' => 'company', 'booking_service_id' => $this->bookingService->id, 'reimbursable' => false]);
    $this->hotel = $categories->save(null, ['type' => 'amount', 'name' => 'Hotel', 'code' => 'HOTEL', 'travel_link' => 'plan', 'travel_form' => 'accommodation', 'booked_by' => 'either']);
    $this->da = $categories->save(null, ['type' => 'amount', 'name' => 'Daily allowance', 'code' => 'DA', 'travel_link' => 'plan', 'travel_form' => 'accommodation', 'booked_by' => 'employee']);
    $this->taxi = $categories->save(null, ['type' => 'amount', 'name' => 'Taxi', 'code' => 'TAXI', 'travel_link' => 'reference', 'travel_form' => 'travel', 'booked_by' => 'employee']);
    $this->cab = $categories->save(null, ['type' => 'amount', 'name' => 'Local cab', 'code' => 'CAB']);
    trvPolicy('expense', ['categories' => [['category' => $this->air->id], ['category' => $this->hotel->id], ['category' => $this->taxi->id], ['category' => $this->cab->id],
        ['category' => $this->da->id, 'limit_amount' => 1000, 'limit_period' => 'trip']]]);
    $this->trips = app(TravelRequests::class);
});

/** @return array{0: User, 1: Employee} */
function trvPerson(Tenant $tenant, Company $company, array $permissions, ?Employee $manager = null): array
{
    $user = tenantUser($tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);
    if ($manager !== null) {
        ReportingRelationship::query()->create(['employee_id' => $employee->id, 'manager_id' => $manager->id, 'type' => 'line', 'is_primary' => true, 'effective_from' => '2025-01-01']);
    }

    return [$user, $employee];
}

function trvPolicy(string $type, array $settings): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'TRV_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Rule', 'conditions' => [], 'priority' => 1000]);

    return $policy;
}

/** A trip to Mumbai: a flight (booked by the company) and a stay. */
function trvData(ExpenseCategory $air, ExpenseCategory $hotel, array $extra = [], string $from = '2026-06-15', string $to = '2026-06-18'): array
{
    return $extra + [
        'purpose' => 'Client workshop', 'start_date' => $from, 'end_date' => $to,
        'legs' => [
            ['expense_category_id' => $air->id, 'mode' => 'air', 'from_city' => 'Delhi', 'to_city' => 'Mumbai', 'to_state' => 'MH', 'starts_on' => $from, 'ends_on' => $to, 'preferred_time' => '08:30'],
            ['expense_category_id' => $hotel->id, 'to_city' => 'Mumbai', 'to_state' => 'MH', 'starts_on' => $from, 'ends_on' => $to],
        ],
    ];
}

it('keeps today\'s behaviour by default: the travel settings, no travel mark, receipts of other categories as before', function () {
    $settings = app(SettingsRepository::class);
    expect($settings->get('expense.report.multiple_travel'))->toBeFalse()
        ->and($settings->get('expense.travel.workflow.split_by'))->toBe('none')
        ->and($settings->get('expense.travel.mixed_categories'))->toBeTrue()
        ->and($settings->get('expense.travel.backdated_days'))->toBe(0)
        ->and($settings->get('expense.travel.backdated_reason_required'))->toBeTrue()
        ->and($settings->get('expense.travel.multi_leg'))->toBeTrue()
        ->and($settings->get('expense.travel.advance_allowed'))->toBeTrue()
        ->and($settings->get('expense.travel.block_overlap'))->toBeTrue()
        ->and($settings->get('expense.travel.same_day_turnaround'))->toBeTrue()
        ->and($settings->get('expense.travel.on_attendance_calendar'))->toBeFalse()
        ->and(collect(config('peopleos_setup.modules.expense.settings'))->where('group', 'Travel')->pluck('key')->all())->toContain('expense.travel.backdated_days', 'expense.report.multiple_travel');

    $receipt = app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-06-09', 'amount' => 300, 'travel_request_id' => 999], $this->user);
    expect($receipt->travel_request_id)->toBeNull()->and($receipt->status)->toBe('unreported');

    $trip = $this->trips->submit($this->employee, trvData($this->air, $this->hotel), $this->user);
    $this->trips->approve($trip, null, $this->managerUser);
    $this->actingAs($this->user);
    expect(collect(Livewire::test(AttendanceCalendar::class, ['month' => '2026-06'])->instance()->calendar()['days'])->pluck('trip')->filter()->all())->toBe([]);
});

it('submits a trip with its legs, keeps only shown travel form fields, enforces mandatory ones and encrypts visa and passport', function () {
    $forms = app(ExpenseForms::class);
    $forms->saveTravel([['key' => 'estimated_cost', 'visible' => true, 'mandatory' => true, 'label' => 'Budget'], ['key' => 'visa_number', 'visible' => true, 'mandatory' => true],
        ['key' => 'attachment', 'visible' => false, 'mandatory' => false]], 'Travel form', $this->admin);

    expect(fn () => $this->trips->submit($this->employee, trvData($this->air, $this->hotel), $this->user))->toThrow(ExpenseRuleViolation::class, 'Fill in Budget.');
    // The visa number is mandatory only for international travel.
    $trip = $this->trips->submit($this->employee, trvData($this->air, $this->hotel, ['estimated_cost' => 25000, 'visa_number' => 'V-1', 'attachments' => ['x']]), $this->user);
    expect($trip->number)->toStartWith('TRV-2026-')->and($trip->status)->toBe('submitted')->and($trip->is_international)->toBeFalse()
        ->and($trip->visa_number)->toBeNull()->and($trip->attachments)->toBeNull()->and((float) $trip->estimated_cost_base)->toBe(25000.0)
        ->and($trip->workflow_group)->toBe('all')
        ->and($trip->legs()->get()->map(fn ($l) => [$l->sequence, $l->kind, $l->booked_by, $l->status])->all())->toBe([[1, 'travel', 'company', 'pending'], [2, 'accommodation', 'employee', 'pending']]);

    expect(fn () => $this->trips->submit($this->employee, trvData($this->air, $this->hotel, ['estimated_cost' => 1, 'is_international' => true], '2026-07-01', '2026-07-03'), $this->user))
        ->toThrow(ExpenseRuleViolation::class, 'Fill in Visa number.');
    $abroad = $this->trips->submit($this->employee, trvData($this->air, $this->hotel, ['estimated_cost' => 1, 'is_international' => true, 'visa_number' => 'VISA-123456', 'passport_number' => 'P1234567'], '2026-07-01', '2026-07-03'), $this->user);
    expect($abroad->visa_number)->toBe('VISA-123456')
        ->and(DB::table('travel_requests')->where('id', $abroad->id)->value('visa_number'))->not->toContain('VISA-123456')
        ->and(DB::table('travel_requests')->where('id', $abroad->id)->value('passport_number'))->not->toContain('P1234567');

    // Legs: travel categories of the policy, inside the trip, with places.
    $leg = fn (array $l) => $this->trips->submit($this->employee, ['purpose' => 'x', 'start_date' => '2026-08-01', 'end_date' => '2026-08-02', 'estimated_cost' => 1, 'legs' => [$l]], $this->user);
    expect(fn () => $leg(['expense_category_id' => $this->cab->id, 'starts_on' => '2026-08-01']))->toThrow(ExpenseRuleViolation::class, 'Local cab is not a travel category.')
        ->and(fn () => $leg(['expense_category_id' => $this->air->id, 'mode' => 'air', 'from_city' => 'A', 'to_city' => 'B', 'starts_on' => '2026-08-05']))->toThrow(ExpenseRuleViolation::class, 'must fall within the trip')
        ->and(fn () => $leg(['expense_category_id' => $this->air->id, 'mode' => 'rocket', 'from_city' => 'A', 'to_city' => 'B', 'starts_on' => '2026-08-01']))->toThrow(ExpenseRuleViolation::class, 'choose how you travel')
        ->and(fn () => $leg(['expense_category_id' => $this->hotel->id, 'to_city' => 'B', 'starts_on' => '2026-08-01']))->toThrow(ExpenseRuleViolation::class, 'give the check-out date')
        ->and(fn () => $this->trips->submit($this->employee, ['purpose' => 'x', 'start_date' => '2026-08-01', 'end_date' => '2026-08-02', 'estimated_cost' => 1, 'legs' => []], $this->user))->toThrow(ExpenseRuleViolation::class, 'Add at least one journey or stay');
});

it('refuses backdated trips beyond the allowed days and asks for the delay reason', function () {
    expect(fn () => $this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-08', '2026-06-11'), $this->user))->toThrow(ExpenseRuleViolation::class, 'cannot start in the past');
    app(SettingsRepository::class)->set('expense.travel.backdated_days', 3, 'Allow backdating');
    expect(fn () => $this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-05', '2026-06-11'), $this->user))->toThrow(ExpenseRuleViolation::class, 'backdated by at most 3 day(s)')
        ->and(fn () => $this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-08', '2026-06-11'), $this->user))->toThrow(ExpenseRuleViolation::class, 'Give the reason for the delay');
    $trip = $this->trips->submit($this->employee, trvData($this->air, $this->hotel, ['backdated_reason' => 'Urgent client call'], '2026-06-08', '2026-06-11'), $this->user);
    expect($trip->backdated_reason)->toBe('Urgent client call');

    app(SettingsRepository::class)->set('expense.travel.backdated_reason_required', false, 'No reason');
    app(SettingsRepository::class)->set('expense.travel.block_overlap', false, 'Overlap ok');
    expect($this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-08', '2026-06-11'), $this->user)->backdated_reason)->toBeNull();
    // HR filing on the employee's behalf is not held to the backdating limit.
    expect($this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-05-01', '2026-05-03'), $this->managerUser)->status)->toBe('submitted');
});

it('refuses overlapping trips, allows a same-day turnaround, and both rules can be switched', function () {
    $this->trips->submit($this->employee, trvData($this->air, $this->hotel), $this->user);
    expect(fn () => $this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-17', '2026-06-20'), $this->user))->toThrow(ExpenseRuleViolation::class, 'The dates overlap travel request');
    expect($this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-18', '2026-06-20'), $this->user)->status)->toBe('submitted');

    app(SettingsRepository::class)->set('expense.travel.same_day_turnaround', false, 'Strict');
    expect(fn () => $this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-20', '2026-06-22'), $this->user))->toThrow(ExpenseRuleViolation::class, 'overlap');
    app(SettingsRepository::class)->set('expense.travel.block_overlap', false, 'Allowed');
    expect($this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-16', '2026-06-17'), $this->user)->status)->toBe('submitted');
});

it('limits legs and workflow groups as the settings say, and raises the trip with its workflow group', function () {
    app(SettingsRepository::class)->set('expense.travel.multi_leg', false, 'One journey');
    $data = trvData($this->air, $this->hotel);
    $data['legs'][] = ['expense_category_id' => $this->air->id, 'mode' => 'rail', 'from_city' => 'Mumbai', 'to_city' => 'Pune', 'starts_on' => '2026-06-16'];
    expect(fn () => $this->trips->submit($this->employee, $data, $this->user))->toThrow(ExpenseRuleViolation::class, 'holds one journey (with its return) and one stay');

    app(SettingsRepository::class)->set('expense.travel.workflow.split_by', 'category', 'Split');
    app(SettingsRepository::class)->set('expense.travel.workflow.exclusive_categories', [$this->air->id], 'Air apart');
    app(SettingsRepository::class)->set('expense.travel.mixed_categories', false, 'One group');
    expect(fn () => $this->trips->submit($this->employee, trvData($this->air, $this->hotel), $this->user))->toThrow(ExpenseRuleViolation::class, 'approved the same way');

    [$nodes, $edges] = linear([['id' => 'appr', 'type' => 'approval', 'name' => 'Travel desk head', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->managerUser->id]]]]);
    publishWorkflow($nodes, $edges, ['name' => 'Air travel approval', 'key' => 'air_travel', 'trigger_event' => 'expense.travel_requested',
        'start_conditions' => [['field' => 'workflow_group', 'operator' => 'equals', 'value' => 'category:AIR']]]);
    $air = $this->trips->submit($this->employee, ['purpose' => 'Flight', 'start_date' => '2026-06-15', 'end_date' => '2026-06-15',
        'legs' => [['expense_category_id' => $this->air->id, 'mode' => 'air', 'from_city' => 'Delhi', 'to_city' => 'Mumbai', 'starts_on' => '2026-06-15']]], $this->user);
    expect($air->workflow_group)->toBe('category:AIR')
        ->and(collect(app(ApprovalCenter::class)->pending($this->managerUser))->where('type', 'travel_request'))->toHaveCount(0);
    $task = WorkflowTask::query()->whereIn('workflow_instance_id', WorkflowInstance::query()->where('subject_id', $air->id)->where('subject_type', $air->getMorphClass())->pluck('id'))->where('status', 'pending')->sole();
    app(WorkflowEngine::class)->completeTask($task, 'approved', 'Go ahead', $this->managerUser);
    expect($air->refresh()->status)->toBe('booking')->and($air->decided_by)->toBe($this->managerUser->id);
});

it('approves in the Approval Center (never one\'s own), raises company bookings for the travel desk, which records them', function () {
    TicketCategory::query()->create(['name' => 'Travel desk', 'code' => 'TRAVEL_DESK', 'purpose' => 'travel_booking']);
    $data = trvData($this->air, $this->hotel);
    $data['legs'][1]['booked_by'] = 'company';
    $trip = $this->trips->submit($this->employee, $data, $this->user);

    expect(fn () => $this->trips->approve($trip, null, $this->user))->toThrow(ExpenseRuleViolation::class, 'You cannot decide your own travel request.')
        ->and(collect(app(ApprovalCenter::class)->pending($this->user))->where('type', 'travel_request'))->toHaveCount(0);
    $item = collect(app(ApprovalCenter::class)->pending($this->managerUser))->firstWhere('type', 'travel_request');
    expect($item)->not->toBeNull()->and($item->title)->toContain($trip->number)->toContain('Mumbai')->and($item->impact)->toContain('2 booked by the company');
    expect(app(ApprovalDecisions::class)->decide($this->managerUser, $item->id, 'approve', 'Approved'))->toBe('Travel approved');

    $trip->refresh();
    $legs = $trip->legs()->get();
    $flightTicket = Ticket::query()->withoutGlobalScopes()->find($legs[0]->booking_ticket_id);
    $hotelTicket = Ticket::query()->withoutGlobalScopes()->find($legs[1]->booking_ticket_id);
    expect($trip->status)->toBe('booking')->and($legs->pluck('status')->all())->toBe(['booking', 'booking'])
        ->and($flightTicket->service_definition_id)->toBe($this->bookingService->id)->and($flightTicket->source)->toBe('system')->and($flightTicket->employee_id)->toBe($this->employee->id)
        ->and($hotelTicket->ticket_category_id)->toBe(TicketCategory::query()->where('code', 'TRAVEL_DESK')->value('id'))->and($hotelTicket->id)->not->toBe($flightTicket->id);

    $bookings = app(TravelBookings::class);
    expect(fn () => $bookings->record($legs[0], ['reference' => 'PNR1'], $this->user))->toThrow(ExpenseRuleViolation::class, 'You cannot record the booking of this leg.')
        ->and(fn () => $bookings->record($legs[0], ['reference' => ''], $this->desk))->toThrow(ExpenseRuleViolation::class, 'Give the booking reference');
    $bookings->record($legs[0], ['reference' => 'PNR1', 'provider' => 'Air India', 'cost' => 6500], $this->desk);
    expect($trip->refresh()->status)->toBe('booking');
    $bookings->record($legs[1], ['reference' => 'HTL-77', 'provider' => 'Sea View', 'cost' => 9000], $this->desk);
    expect($trip->refresh()->status)->toBe('booked')
        ->and($legs[0]->refresh()->booking_details)->toEqual(['reference' => 'PNR1', 'provider' => 'Air India', 'cost' => 6500, 'currency' => 'INR'])
        ->and((float) $legs[1]->refresh()->booking_cost_base)->toBe(9000.0)->and($legs[1]->booked_by_user_id)->toBe($this->desk->id);

    // The travel desk sees the trip and records on its page; the employee sees theirs under Me.
    $this->actingAs($this->desk);
    $this->get(TravelRequestResource::getUrl('view', ['record' => $trip]))->assertOk();
    Livewire::test(LegsRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => ViewTravelRequest::class])->assertOk()->assertSee('PNR1');
    $this->actingAs($this->user);
    expect(TravelRequestResource::getNavigationLabel())->toBe('My travel requests');
    $this->get(TravelRequestResource::getUrl('index'))->assertOk();
});

it('decides the advance with the trip and settles it within the policy\'s days after the trip ends', function () {
    trvPolicy('expense_advance', ['enabled' => true, 'settle_within_days' => 10, 'max_open' => 5]);
    $trip = $this->trips->submit($this->employee, trvData($this->air, $this->hotel, ['advance_amount' => 5000]), $this->user);
    $advance = ExpenseAdvance::query()->sole();
    expect($trip->expense_advance_id)->toBe($advance->id)->and($advance->travel_request_id)->toBe($trip->id)->and($advance->status)->toBe('requested')
        ->and($advance->needed_on->toDateString())->toBe('2026-06-15')
        ->and(collect(app(ApprovalCenter::class)->pending($this->managerUser))->pluck('type')->all())->toContain('travel_request')->not->toContain('expense_advance');

    $this->trips->approve($trip, null, $this->managerUser);
    expect($advance->refresh()->status)->toBe('approved')->and((float) $advance->approved_amount)->toBe(5000.0);
    app(ExpenseAdvances::class)->dispense($advance, 'UTR-1', '2026-06-10', $this->admin);
    expect($advance->refresh()->settle_by->toDateString())->toBe('2026-06-28');

    // Cancelling a trip cancels an advance not yet paid; a rejected trip rejects it; advances inside trips can be switched off.
    $second = $this->trips->submit($this->employee, trvData($this->air, $this->hotel, ['advance_amount' => 100], '2026-07-01', '2026-07-02'), $this->user);
    $this->trips->cancel($second, 'Not needed', $this->user);
    expect($second->refresh()->status)->toBe('cancelled')->and(ExpenseAdvance::query()->find($second->expense_advance_id)->status)->toBe('cancelled');
    $third = $this->trips->submit($this->employee, trvData($this->air, $this->hotel, ['advance_amount' => 100], '2026-07-05', '2026-07-06'), $this->user);
    $this->trips->reject($third, 'No budget', $this->managerUser);
    expect(ExpenseAdvance::query()->find($third->expense_advance_id)->status)->toBe('rejected');
    app(SettingsRepository::class)->set('expense.travel.advance_allowed', false, 'No advances in trips');
    expect(fn () => $this->trips->submit($this->employee, trvData($this->air, $this->hotel, ['advance_amount' => 100], '2026-07-10', '2026-07-11'), $this->user))->toThrow(ExpenseRuleViolation::class, 'Advances are not asked for inside travel requests');
});

it('cancels an approved trip only through approval, never once expenses are claimed against it', function () {
    $trip = $this->trips->submit($this->employee, trvData($this->air, $this->hotel), $this->user);
    $this->trips->approve($trip, null, $this->managerUser);
    expect($this->trips->cancel($trip->refresh(), 'Client postponed', $this->user)->status)->toBe('cancel_requested');
    $item = collect(app(ApprovalCenter::class)->pending($this->managerUser))->firstWhere('type', 'travel_cancellation');
    expect($item->decisionLabels)->toBe(['approve' => 'Approve cancellation', 'reject' => 'Keep trip']);
    app(ApprovalDecisions::class)->decide($this->managerUser, $item->id, 'reject', 'Workshop is on');
    expect($trip->refresh()->status)->toBe('booking');

    $this->trips->cancel($trip, 'Postponed again', $this->user);
    $this->trips->approveCancellation($trip->refresh(), null, $this->managerUser);
    expect($trip->refresh()->status)->toBe('cancelled')->and($trip->legs()->pluck('status')->unique()->all())->toBe(['cancelled']);

    $claimed = $this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-01', '2026-06-04'), $this->managerUser);
    $this->trips->approve($claimed, null, $this->admin);
    app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->hotel->id, 'expense_date' => '2026-06-02', 'amount' => 2000, 'travel_request_id' => $claimed->id], $this->user);
    expect(fn () => $this->trips->cancel($claimed->refresh(), 'x', $this->managerUser))->toThrow(ExpenseRuleViolation::class, '1 expense(s) are claimed against');
});

it('links expenses to approved trips (plan: covering the date) with per-trip limits, and reports to one trip unless allowed', function () {
    $trip = $this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-01', '2026-06-04'), $this->managerUser);
    $receipts = app(ExpenseReceipts::class);
    $capture = fn (array $data) => $receipts->capture($this->employee, $data + ['amount' => 400], $this->user);

    expect(fn () => $capture(['expense_category_id' => $this->da->id, 'expense_date' => '2026-06-02']))->toThrow(ExpenseRuleViolation::class, 'Daily allowance is claimed against an approved travel request')
        ->and(fn () => $capture(['expense_category_id' => $this->da->id, 'expense_date' => '2026-06-02', 'travel_request_id' => $trip->id]))->toThrow(ExpenseRuleViolation::class, 'Choose one of your approved travel requests.');
    $this->trips->approve($trip, null, $this->admin);
    expect(fn () => $capture(['expense_category_id' => $this->da->id, 'expense_date' => '2026-06-06', 'travel_request_id' => $trip->id]))->toThrow(ExpenseRuleViolation::class, 'is outside '.$trip->number);

    // Per trip: 1,000 across the trip's own daily allowances.
    $first = $capture(['expense_category_id' => $this->da->id, 'expense_date' => '2026-06-02', 'travel_request_id' => $trip->id, 'amount' => 700]);
    $second = $capture(['expense_category_id' => $this->da->id, 'expense_date' => '2026-06-03', 'travel_request_id' => $trip->id, 'amount' => 700]);
    expect($first->travel_request_id)->toBe($trip->id)->and((float) $second->claimable_amount)->toBe(300.0)->and($second->hasFlag('over_limit'))->toBeTrue();
    // A reference category may point at a trip, or not.
    expect($capture(['expense_category_id' => $this->taxi->id, 'expense_date' => '2026-06-08'])->travel_request_id)->toBeNull();

    // Reports: one trip each unless multiple travel is allowed; the report covers its expenses' trips.
    $this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-06-05', '2026-06-07'), $this->managerUser);
    $other = TravelRequest::query()->whereDate('start_date', '2026-06-05')->sole();
    $this->trips->approve($other, null, $this->admin);
    $elsewhere = $capture(['expense_category_id' => $this->hotel->id, 'expense_date' => '2026-06-06', 'travel_request_id' => $other->id]);
    $reports = app(ExpenseReports::class);
    $report = $reports->create($this->employee, 'Mumbai trip', [$first->id, $second->id], $this->user);
    expect(ExpenseReportTravel::query()->where('expense_report_id', $report->id)->pluck('travel_request_id')->all())->toBe([$trip->id])
        ->and(fn () => $reports->addReceipts($report, [$elsewhere->id], $this->user))->toThrow(ExpenseRuleViolation::class, 'A report holds the expenses of one trip');
    app(SettingsRepository::class)->set('expense.report.multiple_travel', true, 'Several trips');
    $reports->addReceipts($report, [$elsewhere->id], $this->user);
    expect($report->refresh()->trips()->pluck('travel_requests.id')->all())->toEqualCanonicalizing([$trip->id, $other->id]);
    $reports->removeReceipt($report, $elsewhere, $this->user);
    expect($report->refresh()->trips()->count())->toBe(1);
});

it('marks approved trips on the attendance calendar when switched on', function () {
    $trip = $this->trips->submit($this->employee, trvData($this->air, $this->hotel), $this->user);
    app(SettingsRepository::class)->set('expense.travel.on_attendance_calendar', true, 'Show travel');
    $this->actingAs($this->user);
    $days = fn () => collect(Livewire::test(AttendanceCalendar::class, ['month' => '2026-06'])->instance()->calendar()['days'])->filter(fn ($d) => $d['trip'] !== null);
    expect($days())->toHaveCount(0);
    $this->trips->approve($trip, null, $this->managerUser);
    expect($days()->map(fn ($d) => $d['date']->toDateString())->values()->all())->toBe(['2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18'])
        ->and($days()->first()['trip'])->toBe($trip->number.' · Mumbai');
    Livewire::test(AttendanceCalendar::class, ['month' => '2026-06'])->assertSee('Travel '.$trip->number);
});

it('reports trips and legs in the travel datasets and HROne\'s four travel templates, in scope', function () {
    $live = $this->trips->submit($this->employee, trvData($this->air, $this->hotel, ['estimated_cost' => 30000], '2026-06-09', '2026-06-12'), $this->managerUser);
    $this->trips->approve($live, null, $this->admin);
    $legs = $live->legs()->get();
    app(TravelBookings::class)->record($legs[0], ['reference' => 'PNR9', 'provider' => 'Air India', 'cost' => 5000], $this->desk);
    $this->trips->submit($this->employee, trvData($this->air, $this->hotel, [], '2026-07-01', '2026-07-03'), $this->user);

    $library = app(ReportLibrary::class);
    $runner = app(ReportRunner::class);
    $run = fn (string $key) => $runner->execute($library->find($key)['dataset'], $library->find($key)['definition'], $this->admin)->rows;
    expect(collect($library->all())->whereIn('key', ['live_trip', 'travel_details', 'travel_summary', 'accommodation_details'])->pluck('category')->unique()->all())->toBe(['Expense & travel']);

    $livetrip = $run('live_trip');
    expect($livetrip)->toHaveCount(1)->and($livetrip[0])->toMatchArray(['number' => $live->number, 'destinations' => 'Mumbai', 'bookings_open' => 0]);
    $summary = collect($run('travel_summary'))->keyBy('number');
    expect($summary)->toHaveCount(2)->and($summary[$live->number])->toMatchArray(['days' => 4, 'estimated_cost_base' => 30000.0, 'booked_cost' => 5000.0, 'trip_state' => 'Live']);
    $travel = $run('travel_details');
    expect($travel)->toHaveCount(2)->and(collect($travel)->firstWhere('trip', $live->number))->toMatchArray(['mode' => 'Air', 'from_city' => 'Delhi', 'to_city' => 'Mumbai', 'booked_by' => 'Company', 'booking_reference' => 'PNR9', 'provider' => 'Air India']);
    $stays = $run('accommodation_details');
    expect($stays)->toHaveCount(2)->and(collect($stays)->firstWhere('trip', $live->number))->toMatchArray(['to_city' => 'Mumbai', 'nights' => 3, 'booked_by' => 'Employee']);

    // The employee alone (no expense.view) cannot run them.
    expect(fn () => $runner->execute('travel_requests', $library->find('travel_summary')['definition'], $this->user))->toThrow(RuntimeException::class, 'cannot report')
        ->and(fn () => $runner->execute('travel_request_legs', $library->find('travel_details')['definition'], $this->user))->toThrow(RuntimeException::class, 'cannot report');
});

it('refuses travel without expense.claim, for someone else without approval rights, and lets employees request from the screen', function () {
    $outsider = tenantUser($this->tenant, ['employee.self']);
    $own = Employee::factory()->create(['user_id' => $outsider->id, 'joining_date' => '2025-01-01']);
    expect(fn () => $this->trips->submit($own, trvData($this->air, $this->hotel), $outsider))->toThrow(ExpenseRuleViolation::class, 'You cannot request travel for this employee.')
        ->and(fn () => $this->trips->submit($this->manager, trvData($this->air, $this->hotel), $this->user))->toThrow(ExpenseRuleViolation::class, 'You cannot request travel for this employee.');

    $this->actingAs($this->user);
    Livewire::test(ListTravelRequests::class)->assertOk()
        ->callAction('requestTravel', ['purpose' => 'Plant audit', 'start_date' => '2026-06-20', 'end_date' => '2026-06-21',
            'legs' => [['expense_category_id' => $this->air->id, 'mode' => 'rail', 'from_city' => 'Delhi', 'to_city' => 'Jaipur', 'starts_on' => '2026-06-20']]])
        ->assertHasNoActionErrors();
    $trip = TravelRequest::query()->sole();
    expect($trip->purpose)->toBe('Plant audit')->and($trip->requested_by)->toBe($this->user->id)
        ->and(TravelRequestLeg::query()->where('travel_request_id', $trip->id)->sole()->mode)->toBe('rail')
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'select', 'options_from' => 'expense_categories']))->toHaveKey($this->air->id);
});
