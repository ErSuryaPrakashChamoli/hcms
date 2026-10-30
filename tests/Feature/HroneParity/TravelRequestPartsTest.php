<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Models\TravelRequestPart;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\TravelRequests;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Resources\TravelRequests\Pages\ListTravelRequests;
use App\Filament\Resources\TravelRequests\Pages\ViewTravelRequest;
use App\Filament\Resources\TravelRequests\RelationManagers\PartsRelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* Owner decision 2026-10-29 (EX-F, trips spanning several workflow groups): one trip, one record; approval is routed to
 * every workflow group its legs belong to (one part per group, a workflow run or an Approval Center item showing only its
 * legs); the trip is approved when every group has approved and rejected when any group rejects. Trips of one group are
 * decided as one, as before. */

beforeEach(function () {
    $this->travelTo('2026-06-10 10:00:00');
    Storage::fake('local');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $company = Company::factory()->create();
    [$this->managerUser, $this->manager] = trpPerson($this->tenant, $company, ['expense.claim', 'expense.approve']);
    [$this->user, $this->employee] = trpPerson($this->tenant, $company, ['expense.claim'], $this->manager);
    $this->airHead = tenantUser($this->tenant, ['expense.approve']);

    $categories = app(ExpenseCategories::class);
    $this->air = $categories->save(null, ['type' => 'amount', 'name' => 'Air ticket', 'code' => 'AIR', 'travel_link' => 'plan', 'travel_form' => 'travel', 'booked_by' => 'employee']);
    $this->hotel = $categories->save(null, ['type' => 'amount', 'name' => 'Hotel', 'code' => 'HOTEL', 'travel_link' => 'plan', 'travel_form' => 'accommodation', 'booked_by' => 'employee']);
    trpPolicy('expense', ['categories' => [['category' => $this->air->id], ['category' => $this->hotel->id]]]);
    trpPolicy('expense_advance', ['enabled' => true, 'max_open' => 5]);
    $this->trips = app(TravelRequests::class);
});

/** @return array{0: User, 1: Employee} */
function trpPerson(Tenant $tenant, Company $company, array $permissions, ?Employee $manager = null): array
{
    $user = tenantUser($tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);
    if ($manager !== null) {
        ReportingRelationship::query()->create(['employee_id' => $employee->id, 'manager_id' => $manager->id, 'type' => 'line', 'is_primary' => true, 'effective_from' => '2025-01-01']);
    }

    return [$user, $employee];
}

function trpPolicy(string $type, array $settings): void
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'TRP_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Rule', 'conditions' => [], 'priority' => 1000]);
}

/** A flight and a stay in Mumbai, with an advance. */
function trpData(ExpenseCategory $air, ExpenseCategory $hotel, string $from, string $to): array
{
    return ['purpose' => 'Client workshop', 'start_date' => $from, 'end_date' => $to, 'advance_amount' => 2000, 'legs' => [
        ['expense_category_id' => $air->id, 'mode' => 'air', 'from_city' => 'Delhi', 'to_city' => 'Mumbai', 'starts_on' => $from, 'ends_on' => $to],
        ['expense_category_id' => $hotel->id, 'to_city' => 'Mumbai', 'starts_on' => $from, 'ends_on' => $to],
    ]];
}

/** Air tickets have a workflow of their own (the air travel head); everything else is approved in the Approval Center. */
function trpSplitAir(ExpenseCategory $air, User $airHead): void
{
    app(SettingsRepository::class)->set('expense.travel.workflow.split_by', 'category', 'Split');
    app(SettingsRepository::class)->set('expense.travel.workflow.exclusive_categories', [$air->id], 'Air apart');
    [$nodes, $edges] = linear([['id' => 'appr', 'type' => 'approval', 'name' => 'Air travel head', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $airHead->id]]]]);
    publishWorkflow($nodes, $edges, ['name' => 'Air travel approval', 'key' => 'air_travel', 'trigger_event' => 'expense.travel_requested',
        'start_conditions' => [['field' => 'workflow_group', 'operator' => 'equals', 'value' => 'category:AIR']]]);
}

function trpRuns(Model $subject): Collection
{
    return WorkflowInstance::query()->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->get();
}

it('keeps a trip of one workflow group as one approval, as before', function () {
    $trip = $this->trips->submit($this->employee, trpData($this->air, $this->hotel, '2026-06-15', '2026-06-18'), $this->user);
    expect($trip->workflow_group)->toBe('all')->and($trip->hasParts())->toBeFalse()->and(TravelRequestPart::query()->count())->toBe(0)
        ->and(collect(app(ApprovalCenter::class)->pending($this->managerUser))->pluck('type')->all())->toContain('travel_request')->not->toContain('travel_request_part');
    expect($this->trips->approve($trip, null, $this->managerUser)->status)->toBe('approved');
});

it('routes a trip spanning several workflow groups to every group, each approving only its legs, and approves it when all have', function () {
    trpSplitAir($this->air, $this->airHead);
    $trip = $this->trips->submit($this->employee, trpData($this->air, $this->hotel, '2026-06-15', '2026-06-18'), $this->user);
    $parts = TravelRequestPart::query()->where('travel_request_id', $trip->id)->orderBy('id')->get();
    [$airPart, $restPart] = [$parts->firstWhere('workflow_group', 'category:AIR'), $parts->firstWhere('workflow_group', 'all')];

    // One record with one part per group; each part holds its own legs and gets its own approval.
    expect(TravelRequest::query()->count())->toBe(1)->and($trip->workflow_group)->toBe('mixed')->and($trip->status)->toBe('submitted')
        ->and($parts->pluck('label')->all())->toBe(['Air ticket', 'All expenses'])
        ->and($airPart->legs()->pluck('expense_category_id')->all())->toBe([$this->air->id])
        ->and($restPart->legs()->pluck('expense_category_id')->all())->toBe([$this->hotel->id])
        ->and(trpRuns($airPart))->toHaveCount(1)->and(trpRuns($restPart))->toHaveCount(0)->and(trpRuns($trip))->toHaveCount(0)
        ->and($this->managerUser->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, '(Air ticket)'))->count())->toBe(1);

    // The Approval Center lists the part without a workflow (only its legs), never the trip as a whole.
    $items = collect(app(ApprovalCenter::class)->pending($this->managerUser));
    $item = $items->firstWhere('type', 'travel_request_part');
    expect($items->where('type', 'travel_request'))->toHaveCount(0)->and($items->where('type', 'travel_request_part'))->toHaveCount(1)
        ->and($item->id)->toBe('travel_request_part:'.$restPart->id)->and($item->title)->toContain($trip->number)->toContain('All expenses')
        ->and(collect($item->changes)->pluck('label')->all())->toBe(['Stay · Mumbai'])
        ->and(collect(app(ApprovalCenter::class)->pending($this->user))->where('type', 'travel_request_part'))->toHaveCount(0)
        ->and(fn () => $this->trips->approve($trip, null, $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'decide its parts')
        ->and(fn () => $this->trips->approvePart($restPart, null, $this->user))->toThrow(ExpenseRuleViolation::class, 'You cannot decide your own travel request.');

    // One group approved: the trip still waits for the other, and its advance with it.
    app(ApprovalDecisions::class)->decide($this->managerUser, $item->id, 'approve', 'Hotel fine');
    expect($restPart->refresh()->status)->toBe('approved')->and($trip->refresh()->status)->toBe('submitted')
        ->and(ExpenseAdvance::query()->find($trip->expense_advance_id)->status)->toBe('requested');

    // The air group's workflow approves its part: every group has approved, so the trip is approved.
    $task = WorkflowTask::query()->whereIn('workflow_instance_id', trpRuns($airPart)->pluck('id'))->where('status', 'pending')->sole();
    app(WorkflowEngine::class)->completeTask($task, 'approved', 'Fare within policy', $this->airHead);
    expect($airPart->refresh()->only(['status', 'decided_by']))->toBe(['status' => 'approved', 'decided_by' => $this->airHead->id])
        ->and($trip->refresh()->status)->toBe('approved')->and($trip->decided_by)->toBe($this->airHead->id)
        ->and(ExpenseAdvance::query()->find($trip->expense_advance_id)->status)->toBe('approved')
        ->and(collect(app(ApprovalCenter::class)->pending($this->managerUser))->whereIn('type', ['travel_request', 'travel_request_part']))->toHaveCount(0);
});

it('rejects the trip when any group rejects, cancelling the other groups\' approvals', function () {
    trpSplitAir($this->air, $this->airHead);
    $trip = $this->trips->submit($this->employee, trpData($this->air, $this->hotel, '2026-06-15', '2026-06-18'), $this->user);
    $airPart = TravelRequestPart::query()->where('workflow_group', 'category:AIR')->sole();
    $restPart = TravelRequestPart::query()->where('workflow_group', 'all')->sole();

    expect(fn () => $this->trips->rejectPart($restPart, '', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'Give the reason for rejecting.');
    $this->trips->rejectPart($restPart, 'Use the guest house', $this->managerUser);
    expect($restPart->refresh()->status)->toBe('rejected')->and($airPart->refresh()->status)->toBe('cancelled')
        ->and(trpRuns($airPart)->first()->status)->toBe(InstanceStatus::Cancelled)
        ->and($trip->refresh()->only(['status', 'decided_by']))->toBe(['status' => 'rejected', 'decided_by' => $this->managerUser->id])
        ->and(ExpenseAdvance::query()->find($trip->expense_advance_id)->status)->toBe('rejected')
        ->and(fn () => $this->trips->approvePart($airPart->refresh(), null, $this->airHead))->toThrow(ExpenseRuleViolation::class, 'already been decided');

    // A trip cancelled while it waits cancels its parts and their workflows.
    $second = $this->trips->submit($this->employee, trpData($this->air, $this->hotel, '2026-07-01', '2026-07-02'), $this->user);
    $this->trips->cancel($second, 'Not needed', $this->user);
    $secondParts = TravelRequestPart::query()->where('travel_request_id', $second->id)->get();
    expect($second->refresh()->status)->toBe('cancelled')->and($secondParts->pluck('status')->unique()->all())->toBe(['cancelled'])
        ->and(trpRuns($secondParts->firstWhere('workflow_group', 'category:AIR'))->first()->status)->toBe(InstanceStatus::Cancelled);
});

it('shows the approvals by workflow group on the trip, where approvers decide the parts no workflow runs for', function () {
    trpSplitAir($this->air, $this->airHead);
    $trip = $this->trips->submit($this->employee, trpData($this->air, $this->hotel, '2026-06-15', '2026-06-18'), $this->user);
    $airPart = TravelRequestPart::query()->where('workflow_group', 'category:AIR')->sole();
    $restPart = TravelRequestPart::query()->where('workflow_group', 'all')->sole();
    $single = TravelRequest::query()->whereKey($this->trips->submit($this->manager, ['purpose' => 'Hotel only', 'start_date' => '2026-06-20', 'end_date' => '2026-06-21',
        'legs' => [['expense_category_id' => $this->hotel->id, 'to_city' => 'Pune', 'starts_on' => '2026-06-20', 'ends_on' => '2026-06-21']]], $this->managerUser)->id)->sole();

    $this->actingAs($this->managerUser);
    Livewire::test(ListTravelRequests::class)->assertTableActionHidden('approve', $trip)->assertTableActionHidden('reject', $trip);
    expect(PartsRelationManager::canViewForRecord($trip, ViewTravelRequest::class))->toBeTrue()
        ->and(PartsRelationManager::canViewForRecord($single, ViewTravelRequest::class))->toBeFalse();
    Livewire::test(PartsRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => ViewTravelRequest::class])->assertOk()
        ->assertSee('Air ticket')->assertSee('Stay · Mumbai')
        ->assertTableActionHidden('approvePart', $airPart) // its workflow decides it
        ->callTableAction('approvePart', $restPart, ['note' => 'Fine'])->assertHasNoTableActionErrors();
    expect($restPart->refresh()->status)->toBe('approved')->and($trip->refresh()->status)->toBe('submitted');

    $this->actingAs($this->user);
    Livewire::test(PartsRelationManager::class, ['ownerRecord' => $trip, 'pageClass' => ViewTravelRequest::class])->assertOk()
        ->assertTableActionHidden('rejectPart', $airPart);
});
