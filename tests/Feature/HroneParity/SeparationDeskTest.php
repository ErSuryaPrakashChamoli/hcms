<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Exit\Models\ClearanceChecklist;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Models\ExitWithdrawalRequest;
use App\Domain\Exit\Services\ExitHandover;
use App\Domain\Exit\Services\Exits;
use App\Domain\Exit\Services\ExitWithdrawals;
use App\Domain\Exit\Services\FinalSettlements;
use App\Domain\Exit\Services\RelievingReview;
use App\Domain\Exit\Services\SeparationDesk;
use App\Domain\Exit\Support\ClearanceTaskSource;
use App\Domain\Exit\Support\NoticeShortfall;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Experience\Sources\ExitApprovalSource;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\SalaryHold;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\TaskDelegations;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Resources\ExitCases\Pages\ListExitCases;
use App\Filament\Resources\ExitCases\Pages\ViewExitCase;
use App\Filament\Resources\ExitClearances\ExitClearanceResource;
use App\Filament\Resources\ExitClearances\Pages\ListExitClearances;
use App\Filament\Support\SeparationActions;
use App\Http\Controllers\ParityFileController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity WO-11 (matrix 23 §23.6.1–§23.6.4, §23.2.2 #6–7, #20, §23.11 #10–11, #17): the separation desk — accept /
| reject without a workflow, the approver's reason, change of last working day, notice relaxation and the notice shortfall
| line on the exit policy's rule, rehire eligibility, withdrawal requests with approval (D-WO8), the Clearances register
| with due dates, evidence, delegation and "not applicable — exit withdrawn", the roles / team hand-over (D-WO10), the
| relieving review and the My tasks source. Every switch is off by default and reproduces the earlier behaviour.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->exits = app(Exits::class);
    $this->desk = app(SeparationDesk::class);
    $this->manager = employeeWithUser(null, ['exit.clear', 'task.view', 'task.act', 'task.delegate']);
    forceLifecycle($this->manager, LifecycleState::Active, ['joining_date' => '2024-01-01']);
    $this->employee = employeeWithUser($this->manager, ['exit.resign', 'task.view']);
    forceLifecycle($this->employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
});

function sdSet(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'WO-11 test');
}

function sdExitPolicy(array $settings, string $from = '2020-01-01'): Policy
{
    $policy = Policy::query()->where('code', 'EXIT_SD')->first() ?? Policy::create(['type' => 'exit', 'name' => 'Separation', 'code' => 'EXIT_SD']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, $from);
    PolicyAssignmentRule::query()->firstOrCreate(['policy_type' => 'exit', 'policy_id' => $policy->id], ['name' => 'All', 'conditions' => []]);

    return $policy;
}

/** @return list<string> the request types the employee may raise now (as the employee, a fresh registry) */
function sdOffered(Employee $employee): array
{
    $before = auth()->user();
    app()->forgetInstance(RequestRegistry::class);
    test()->actingAs($employee->user);
    $keys = collect(app(RequestRegistry::class)->available($employee->user))->map(fn (array $e) => $e['type']->key())->values()->all();
    app()->forgetInstance(RequestRegistry::class);
    test()->actingAs($before);

    return $keys;
}

function sdSeparationReason(string $name, array $extra = []): WorkforceReason
{
    return WorkforceReason::create($extra + ['kind' => 'separation', 'name' => $name, 'status' => 'active', 'for_employee' => true, 'for_approver' => true]);
}

// ---- defaults ---------------------------------------------------------------------------------------------------------

it('keeps today\'s behaviour by default: no acceptance step, immediate withdrawal, no due dates, no tasks, no new approvals', function () {
    foreach (['exit.resignation.requires_acceptance', 'exit.withdrawal.requires_approval', 'exit.clearance.my_tasks'] as $key) {
        expect(config('peopleos.settings')[$key])->toBeFalse()->and(app(SettingsRepository::class)->get($key))->toBeFalse();
    }

    $case = $this->exits->resign($this->employee, 'Relocating', null, $this->employee->user);
    expect($case->notice_relaxation_days)->toBe(0)
        ->and($case->clearances()->whereNotNull('due_on')->count())->toBe(0)
        ->and(app(ExitApprovalSource::class)->pending($this->hr))->toBe([])
        ->and($this->desk->awaitsAcceptance($case))->toBeFalse()
        ->and(app(RequestRegistry::class)->all()['resignation']->status($case->refresh()))->toBe(RequestStatus::Approved)
        ->and(sdOffered($this->employee))->not->toContain('resignation_withdrawal');

    // No decide action; withdrawing is immediate and returns the case (as before).
    Livewire::test(ViewExitCase::class, ['record' => $case->id])->assertActionHidden('decideResignation')->assertActionVisible('withdraw');
    $result = app(ExitWithdrawals::class)->request($case, $this->employee->user, 'Changed my mind');
    expect($result)->toBeInstanceOf(ExitCase::class)->and($result->status)->toBe('withdrawn')
        ->and($this->employee->refresh()->lifecycle_state)->toBe(LifecycleState::Active)
        ->and(ExitWithdrawalRequest::query()->count())->toBe(0);

    // Clearance tasks stay off.
    $second = $this->exits->initiate($this->employee, 'resignation', 'Again', '2026-09-21', '2026-09-25', 30, $this->hr);
    expect($second->status)->toBe('clearance')
        ->and(app(ClearanceTaskSource::class)->tasksFor($this->manager->user, $this->manager))->toHaveCount(0)
        ->and(app(ClearanceTaskSource::class)->tasksFor($this->hr, null))->toHaveCount(0);
});

// ---- acceptance -------------------------------------------------------------------------------------------------------

it('lets HR accept a resignation with the approver\'s reason, a new last working day and relaxation, when the switch is on', function () {
    sdSet('exit.resignation.requires_acceptance', true);
    $employeeReason = sdSeparationReason('Better opportunity', ['for_approver' => false]);
    $approverReason = sdSeparationReason('Performance concerns', ['for_employee' => false, 'separation_kind' => 'involuntary', 'approver_remark_required' => true]);
    $case = $this->exits->resign($this->employee, 'Moving on', null, $this->employee->user, separationReasonId: $employeeReason->id);

    expect($this->desk->awaitsAcceptance($case))->toBeTrue()
        ->and(app(RequestRegistry::class)->all()['resignation']->status($case->refresh()))->toBe(RequestStatus::Pending)
        ->and(app(RequestRegistry::class)->all()['resignation']->statusLabel($case))->toBe('Waiting for acceptance')
        ->and(collect(app(ExitApprovalSource::class)->pending($this->hr))->pluck('id')->all())->toBe(['resignation_acceptance:'.$case->id])
        ->and(app(ExitApprovalSource::class)->pending($this->employee->user))->toBe([]);
    Livewire::test(ViewExitCase::class, ['record' => $case->id])->assertActionVisible('decideResignation');

    // Completing waits for the acceptance.
    expect(fn () => $this->desk->assertReadyToComplete($case))->toThrow(RuntimeException::class, 'Accept the resignation');
    // The approver's remark is required by that reason; nobody accepts their own.
    expect(fn () => $this->desk->decide($case, $this->hr, 'accept', 'ok', ['approver_reason_id' => $approverReason->id]))->toThrow(RuntimeException::class, 'needs a remark')
        ->and(fn () => $this->desk->decide($case, $this->employee->user, 'accept'))->toThrow(RuntimeException::class, 'cannot decide');

    $accepted = $this->desk->decide($case->refresh(), $this->hr, 'accept', 'Agreed', [
        'approver_reason_id' => $approverReason->id, 'approver_remark' => 'Agreed with the manager',
        'last_working_day' => '2026-10-10', 'last_working_day_reason' => 'Project ends early', 'notice_relaxation_days' => 3,
    ]);
    expect($accepted->resignation_accepted_by)->toBe($this->hr->id)
        ->and($accepted->workforce_reason_id)->toBe($employeeReason->id)   // the employee's reason stays
        ->and($accepted->approver_reason_id)->toBe($approverReason->id)    // the approver's apart from it
        ->and($accepted->last_working_day->toDateString())->toBe('2026-10-10')
        ->and($accepted->notice_relaxation_days)->toBe(3)
        ->and(NoticeShortfall::days($accepted))->toBe(30 - 19 - 3)
        ->and($this->desk->awaitsAcceptance($accepted))->toBeFalse()
        ->and(app(ExitApprovalSource::class)->pending($this->hr))->toBe([]);
});

it('withdraws a resignation HR does not accept, from the Approval Center, and refuses a decision while the switch is off', function () {
    $case = $this->exits->resign($this->employee, 'Moving on', null, $this->employee->user);
    expect(fn () => $this->desk->decide($case, $this->hr, 'reject', 'No'))->toThrow(RuntimeException::class, 'without an acceptance step');

    sdSet('exit.resignation.requires_acceptance', true);
    app(ApprovalCenter::class)->forget($this->hr);
    expect(fn () => app(ApprovalDecisions::class)->decide($this->hr, 'resignation_acceptance:'.$case->id, 'reject', ''))->toThrow(Exception::class);
    app(ApprovalCenter::class)->forget($this->hr);
    app(ApprovalDecisions::class)->decide($this->hr, 'resignation_acceptance:'.$case->id, 'reject', 'Counter-offer agreed');

    expect($case->refresh()->status)->toBe('withdrawn')->and($this->employee->refresh()->lifecycle_state)->toBe(LifecycleState::Active)
        ->and($case->clearances()->where('status', '!=', 'na')->count())->toBe(0);
});

// ---- withdrawal requests (D-WO8) --------------------------------------------------------------------------------------

it('turns a withdrawal into a request that HR approves or rejects when withdrawals need approval', function () {
    sdSet('exit.withdrawal.requires_approval', true);
    $case = $this->exits->resign($this->employee, 'Moving on', null, $this->employee->user);
    expect(sdOffered($this->employee))->toContain('resignation_withdrawal');

    $request = app(ExitWithdrawals::class)->request($case, $this->employee->user, 'Family reasons');
    expect($request)->toBeInstanceOf(ExitWithdrawalRequest::class)->and($request->status)->toBe('pending')
        ->and($case->refresh()->status)->toBe('notice')
        ->and($this->employee->refresh()->lifecycle_state)->toBe(LifecycleState::NoticePeriod)
        ->and(fn () => app(ExitWithdrawals::class)->request($case, $this->employee->user, 'Again'))->toThrow(RuntimeException::class, 'already waiting')
        ->and(fn () => $this->desk->assertReadyToComplete($case))->toThrow(RuntimeException::class, 'waiting for a decision')
        ->and(sdOffered($this->employee))->not->toContain('resignation_withdrawal');

    // In the Approval Center of HR, never of the employee; the requester cannot decide it.
    expect(collect(app(ExitApprovalSource::class)->pending($this->hr))->pluck('id')->all())->toBe(['exit_withdrawal:'.$request->id])
        ->and(app(ExitApprovalSource::class)->pending($this->employee->user))->toBe([])
        ->and(fn () => app(ExitWithdrawals::class)->approve($request, $this->employee->user))->toThrow(RuntimeException::class, 'cannot decide');
    // RQ-1: the employee follows it under My requests.
    $type = app(RequestRegistry::class)->all()['resignation_withdrawal'];
    expect($type->ownRecords($this->employee->user, $this->employee)->pluck('id')->all())->toBe([$request->id])->and($type->status($request))->toBe(RequestStatus::Pending);

    app(ExitWithdrawals::class)->approve($request, $this->hr, 'Welcome back');
    expect($request->refresh()->status)->toBe('approved')->and($request->open_key)->toBeNull()
        ->and($case->refresh()->status)->toBe('withdrawn')
        ->and($this->employee->refresh()->lifecycle_state)->toBe(LifecycleState::Active);

    // A rejected request lets the exit go on.
    $again = $this->exits->resign($this->employee, 'Leaving after all', null, $this->employee->user);
    $second = app(ExitWithdrawals::class)->request($again, $this->hr, 'HR asks to withdraw');
    expect(fn () => app(ExitWithdrawals::class)->approve($second, $this->hr))->toThrow(RuntimeException::class, 'cannot decide'); // the requester
    $otherHr = tenantUser($this->tenant, ['exit.manage', 'exit.view']);
    expect(fn () => app(ExitWithdrawals::class)->reject($second, $otherHr, ''))->toThrow(RuntimeException::class, 'reason for rejecting');
    app(ExitWithdrawals::class)->reject($second, $otherHr, 'Notice stands');
    expect($second->refresh()->status)->toBe('rejected')->and($again->refresh()->status)->toBe('notice');
});

it('lets a workflow on exit.withdrawal_requested decide the request, out of the Approval Center meanwhile', function () {
    sdSet('exit.withdrawal.requires_approval', true);
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->manager->user_id]]]]);
    publishWorkflow($nodes, $edges, ['trigger_event' => 'exit.withdrawal_requested', 'subject_type' => null]);
    expect(config('peopleos.workflows.trigger_events'))->toHaveKey('exit.withdrawal_requested')
        ->and(config('peopleos.workflows.subject_types'))->toHaveKey(ExitWithdrawalRequest::class);

    $case = $this->exits->resign($this->employee, 'Moving on', null, $this->employee->user);
    $request = app(ExitWithdrawals::class)->request($case, $this->employee->user, 'Family reasons');
    expect(app(ExitApprovalSource::class)->pending($this->hr))->toBe([]);

    $instance = WorkflowInstance::query()->where('subject_type', $request->getMorphClass())->where('subject_id', $request->id)->sole();
    app(WorkflowEngine::class)->completeTask($instance->tasks()->where('status', TaskStatus::Pending)->firstOrFail(), 'approved', 'Fine', $this->manager->user);

    expect($request->refresh()->status)->toBe('approved')->and($request->decided_by)->toBe($this->manager->user_id)
        ->and($case->refresh()->status)->toBe('withdrawn');
});

// ---- access scope -----------------------------------------------------------------------------------------------------

it('keeps decisions, the register and evidence within the access scope', function () {
    sdSet('exit.withdrawal.requires_approval', true);
    sdSet('exit.resignation.requires_acceptance', true);
    $case = $this->exits->resign($this->employee, 'Moving on', null, $this->employee->user);
    $request = app(ExitWithdrawals::class)->request($case, $this->employee->user, 'Stay');
    $other = Company::factory()->create();
    $scoped = tenantUser($this->tenant, ['exit.manage', 'exit.view']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$other->id]], 'Other company');

    expect(app(ExitApprovalSource::class)->pending($scoped))->toBe([])
        ->and(app(ExitWithdrawals::class)->mayDecide($scoped, $request))->toBeFalse()
        ->and($this->desk->mayDecide($scoped, $case))->toBeFalse()
        ->and(fn () => $this->desk->changeLastWorkingDay($case, '2026-10-01', 'x', $scoped))->toThrow(RuntimeException::class, 'cannot change');

    // Register: HR sees every stage; a stage owner only their own; the scoped HR none of this exit.
    expect(ExitClearanceResource::getEloquentQuery()->count())->toBe(5);
    $this->actingAs($this->manager->user);
    expect(ExitClearanceResource::getEloquentQuery()->pluck('stage')->all())->toBe(['manager'])
        ->and(ExitClearanceResource::shouldRegisterNavigation())->toBeFalse()->and(ExitClearanceResource::canAccess())->toBeTrue();
    $this->actingAs($scoped);
    expect(ExitClearanceResource::getEloquentQuery()->count())->toBe(0);

    // Evidence: stored under the tenant folder, served to whoever may see the exit, not to others.
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->actingAs($this->hr);
    $stage = $case->clearances()->where('stage', 'it')->firstOrFail();
    Storage::disk('local')->put('exit-clearances/upload.pdf', "%PDF-1.4\n%test\n");
    $this->desk->attachEvidence($stage, 'exit-clearances/upload.pdf', 'laptop-wiped.pdf', $this->hr);
    expect($stage->refresh()->evidence_path)->toStartWith('tenants/'.$this->tenant->id.'/exit-clearances/'.$stage->id.'/')->and($stage->evidence_name)->toBe('laptop-wiped.pdf')
        ->and(DataInventory::FILES['exit_clearances'][0]['path'])->toBe('evidence_path');
    Storage::disk('local')->assertExists($stage->evidence_path);
    $url = ParityFileController::url('exit-clearance', $stage, 0);
    $this->get($url)->assertOk();
    $stranger = employeeWithUser(null, ['exit.resign']);
    $this->actingAs($stranger->user)->get($url)->assertForbidden();
    $this->actingAs($scoped)->get($url)->assertForbidden();
});

// ---- last working day and the shortfall line --------------------------------------------------------------------------

it('changes the last working day within the policy and recovers the shortfall less relaxation in the settlement', function () {
    syncComplianceRules();
    payrollCompany();
    $leaver = salariedEmployee(600000, ['exit.resign'], '2025-01-01', ['CONV' => 1600], $this->manager);
    sdExitPolicy(['hr_backdate_days' => 5, 'clearance_due_it' => 2]);
    $case = $this->exits->initiate($leaver, 'resignation', 'Short notice', '2026-09-21', null, 30, $this->hr);
    expect($case->last_working_day->toDateString())->toBe('2026-10-21')
        ->and($case->clearances()->where('stage', 'it')->first()->due_on->toDateString())->toBe('2026-10-19');

    expect(fn () => $this->desk->changeLastWorkingDay($case, '2026-10-01', '', $this->hr))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $this->desk->changeLastWorkingDay($case, '2026-09-20', 'Earlier', $this->hr))->toThrow(RuntimeException::class, 'before the resignation date');
    $this->travelTo('2026-10-12 09:00:00');
    expect(fn () => $this->desk->changeLastWorkingDay($case, '2026-10-01', 'Backdated', $this->hr))->toThrow(RuntimeException::class, 'at most 5 day(s)');
    $this->travelTo('2026-09-21 09:00:00');

    $this->desk->changeLastWorkingDay($case, '2026-10-01', 'Released early', $this->hr);
    expect($case->refresh()->last_working_day->toDateString())->toBe('2026-10-01')
        ->and($leaver->refresh()->exit_date->toDateString())->toBe('2026-10-01')
        ->and($case->clearances()->where('stage', 'it')->first()->due_on->toDateString())->toBe('2026-09-29')
        ->and($case->status)->toBe('notice') // ten days out: still outside the clearance lead window
        ->and(NoticeShortfall::days($case))->toBe(20)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', $case->getMorphClass())->where('entity_id', (string) $case->id)->where('reason', 'Released early')->exists())->toBeTrue();

    expect(fn () => $this->desk->setNoticeRelaxation($case, 31, 'Too many', $this->hr))->toThrow(RuntimeException::class, 'between 0 and 30');
    $this->desk->setNoticeRelaxation($case, 5, 'Last day is a holiday', $this->hr);
    foreach ($case->clearances()->get() as $stage) {
        $this->exits->markNotApplicable($stage, $this->hr, 'n/a');
    }
    $settlement = app(FinalSettlements::class)->calculate($case->refresh(), $this->hr);
    $line = $settlement->lines->firstWhere('code', 'NOTICE_RECOVERY');
    expect($line->basis['served_days'])->toBe(10)->and($line->basis['relaxation_days'])->toBe(5)
        ->and((float) $line->amount)->toBe(round(15 * round(47600 / 30, 2), 2));

    // The exit policy's own rule: basic ÷ a fixed 30 days, through the settlement source; then "do not recover".
    sdExitPolicy(['hr_backdate_days' => 5, 'notice_recovery_rule' => 'policy', 'notice_recovery_basis' => 'basic', 'notice_recovery_divisor' => 'fixed', 'notice_recovery_days' => 30], '2026-09-01');
    $settlement = app(FinalSettlements::class)->calculate($case->refresh(), $this->hr);
    $lines = $settlement->lines->where('code', 'NOTICE_RECOVERY');
    expect($lines)->toHaveCount(1)->and($lines->first()->basis['rule'])->toBe('exit policy')->and((float) $lines->first()->basis['monthly'])->toBe(20000.0)
        ->and((float) $lines->first()->amount)->toBe(round(15 * round(20000 / 30, 2), 2))
        ->and($settlement->inputs['notice_recovery_rule'])->toBe('policy');

    sdExitPolicy(['notice_recovery_rule' => 'policy', 'notice_recovery_basis' => 'basic', 'notice_recovery_divisor' => 'month_days'], '2026-09-10');
    expect((float) app(FinalSettlements::class)->calculate($case->refresh(), $this->hr)->lines->firstWhere('code', 'NOTICE_RECOVERY')->amount)->toBe(round(15 * round(20000 / 31, 2), 2));

    sdExitPolicy(['notice_recovery_rule' => 'none'], '2026-09-15');
    expect(app(FinalSettlements::class)->calculate($case->refresh(), $this->hr)->lines->pluck('code'))->not->toContain('NOTICE_RECOVERY');

    // Settlement month: never before the last working day's month; recorded with the inputs.
    expect(fn () => $this->desk->setSettlementMonth($case, '2026-09', $this->hr))->toThrow(RuntimeException::class, 'before the month');
    $this->desk->setSettlementMonth($case, '2026-11', $this->hr);
    expect(app(FinalSettlements::class)->calculate($case->refresh(), $this->hr)->inputs['settlement_month'])->toBe('2026-11');
});

// ---- clearance stages -------------------------------------------------------------------------------------------------

it('gives each stage a due date from the checklist or the policy, blank otherwise', function () {
    $checklist = ClearanceChecklist::create(['name' => 'Tech', 'items' => [['stage' => 'it', 'item' => 'Wipe laptop', 'due_days' => 3], ['stage' => 'it', 'item' => 'Revoke VPN', 'due_days' => 1], ['stage' => 'finance', 'item' => 'Close card']]]);
    sdExitPolicy(['clearance_checklist_id' => $checklist->id, 'clearance_due_it' => 10, 'clearance_due_hr' => -2]);
    $case = $this->exits->initiate($this->employee, 'resignation', 'Higher studies', '2026-09-21', '2026-10-21', 30, $this->hr);
    $due = $case->clearances()->pluck('due_on', 'stage')->map(fn ($d) => $d ? substr((string) $d, 0, 10) : null)->all();

    expect($due)->toBe(['manager' => null, 'it' => '2026-10-18', 'finance' => null, 'asset' => null, 'hr' => '2026-10-23']);
    $stage = $case->clearances()->where('stage', 'it')->firstOrFail();
    $this->desk->setExpectedDate($stage, '2026-10-17', $this->hr);
    expect($stage->refresh()->expected_on->toDateString())->toBe('2026-10-17');
    $this->travelTo('2026-10-19 09:00:00');
    expect($stage->refresh()->isOverdue())->toBeTrue();
});

it('marks the open stages of a withdrawn exit not applicable and leaves cleared ones as they are', function () {
    $case = $this->exits->initiate($this->employee, 'resignation', 'Higher studies', '2026-09-21', '2026-09-25', 30, $this->hr);
    expect($case->status)->toBe('clearance');
    $it = $case->clearances()->where('stage', 'it')->firstOrFail();
    $this->exits->clearStage($it, $this->hr, 'Done');
    $this->exits->blockStage($case->clearances()->where('stage', 'finance')->firstOrFail(), $this->hr, 'Card missing');
    $open = $case->clearances()->whereIn('status', ['pending', 'blocked'])->pluck('stage')->all();
    $cleared = $case->clearances()->where('status', 'cleared')->pluck('stage')->all();
    expect($open)->toContain('finance', 'manager')->and($cleared)->toContain('it');

    $this->exits->withdraw($case->refresh(), 'Stays on', $this->hr);
    // (Eloquent collections' only() / except() go by primary key: filter by stage.)
    $stages = $case->clearances()->get();
    $of = fn (array $names) => $stages->filter(fn (ExitClearance $s) => in_array($s->stage, $names, true));
    expect($of($cleared)->pluck('status')->unique()->values()->all())->toBe(['cleared'])
        ->and($of($cleared)->pluck('closed_reason')->filter()->all())->toBe([])
        ->and($of($open)->pluck('status')->unique()->values()->all())->toBe(['na'])
        ->and($of($open)->pluck('closed_reason')->unique()->values()->all())->toBe(['withdrawn'])
        ->and($stages->firstWhere('stage', 'finance')->remarks)->toBe('Not applicable — exit withdrawn');
});

it('cancels stages with a reason in bulk (D-WO3), starts clearance from the Clearances and Employees registers', function () {
    $case = $this->exits->initiate($this->employee, 'resignation', 'Higher studies', '2026-09-21', '2026-10-30', 30, $this->hr);
    $stages = $case->clearances()->orderBy('sort_order')->get();
    $run = app(BulkRuns::class)->start($this->hr, 'exit_clearances', 'exit_clearances.start', [$stages[0]->id, $stages[1]->id], []);
    expect($run->done)->toBe(1)->and($run->refused)->toBe(1)->and($case->refresh()->status)->toBe('clearance');

    $this->exits->clearStage($stages[0]->refresh(), $this->hr, 'ok');
    $run = app(BulkRuns::class)->start($this->hr, 'exit_clearances', 'exit_clearances.cancel', [$stages[0]->id, $stages[2]->id], ['reason' => 'Handled by the parent company']);
    expect($run->done)->toBe(1)->and($run->refused)->toBe(1)
        ->and($stages[2]->refresh()->status)->toBe('na')->and($stages[2]->closed_reason)->toBe('cancelled')->and($stages[2]->remarks)->toBe('Cancelled: Handled by the parent company')
        ->and(ExitClearance::query()->count())->toBe(5); // nothing deleted

    $other = employeeWithUser($this->manager, ['exit.resign']);
    forceLifecycle($other, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $otherCase = $this->exits->initiate($other, 'resignation', 'Higher studies', '2026-09-21', '2026-10-30', 30, $this->hr);
    $run = app(BulkRuns::class)->start($this->hr, 'employees', 'employees.start_clearance', [$other->id, $this->manager->id], []);
    expect($run->done)->toBe(1)->and($run->refused)->toBe(1)->and($otherCase->refresh()->status)->toBe('clearance');
    Livewire::test(ListExitClearances::class)->assertOk()->assertCanSeeTableRecords($otherCase->clearances()->get());
});

it('lets the out-of-office delegate of a stage owner act on the stage, never on their own exit', function () {
    sdSet('inbox.delegation.types', ['exit_clearance']);
    $colleague = employeeWithUser(null, ['exit.clear', 'task.view']);
    forceLifecycle($colleague, LifecycleState::Active, ['joining_date' => '2024-01-01']);
    $case = $this->exits->initiate($this->employee, 'resignation', 'Higher studies', '2026-09-21', '2026-09-25', 30, $this->hr);
    $stage = $case->clearances()->where('stage', 'manager')->firstOrFail();
    expect($stage->isOwnedBy($colleague->user))->toBeFalse();

    app(TaskDelegations::class)->create($this->manager->user, $this->manager->user, $colleague->user, '2026-09-21', '2026-09-30', ['exit_clearance'], 'Annual leave');
    expect($stage->isOwnedBy($colleague->user))->toBeTrue()
        ->and($colleague->user->can('view', $case))->toBeTrue();

    sdSet('exit.clearance.my_tasks', true);
    $tasks = app(ClearanceTaskSource::class)->tasksFor($colleague->user, $colleague);
    expect($tasks->pluck('reference')->all())->toBe([$case->number])->and($tasks->first()->kind)->toBe('clearance');
});

// ---- hand-over (D-WO10) -----------------------------------------------------------------------------------------------

it('lists the leaver\'s roles and team, hands them over (audited), and only warns before completing', function () {
    $approverRole = Role::factory()->create(['name' => 'Leave approver']);
    $this->manager->user->roles()->attach($approverRole->id);
    $successor = employeeWithUser(null, ['task.view']);
    forceLifecycle($successor, LifecycleState::Active, ['joining_date' => '2024-01-01']);
    $case = $this->exits->initiate($this->manager, 'resignation', 'Higher studies', '2026-09-21', '2026-09-25', 30, $this->hr);
    $handover = app(ExitHandover::class);

    expect(collect($handover->rolesHeld($case))->pluck('name'))->toContain('Leave approver')
        ->and($handover->reportees($case)->pluck('employee_id')->all())->toBe([$this->employee->id])
        ->and($handover->unresolved($case))->toContain('Still holds the role "Leave approver"')
        ->and(SeparationActions::completionWarning($case))->toContain('Warning — still to hand over');

    $handover->handOverRole($case, $approverRole->id, $successor->user, $this->hr, 'Takes the approvals');
    expect($successor->user->roles()->where('roles.id', $approverRole->id)->exists())->toBeTrue()
        ->and($this->manager->user->roles()->where('roles.id', $approverRole->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::RoleChanged->value)->where('reason', 'like', 'Exit hand-over '.$case->number.'%')->count())->toBeGreaterThanOrEqual(2);

    expect(fn () => $handover->changeManager($case, [$this->employee->id], $this->manager, $this->hr, 'x'))->toThrow(RuntimeException::class, 'other than the leaver');
    expect($handover->changeManager($case, [$this->employee->id], $successor, $this->hr, 'New lead'))->toBe(1);
    expect(ReportingRelationship::query()->where('employee_id', $this->employee->id)->where('manager_id', $successor->id)->first()->effective_from->toDateString())->toBe('2026-09-26')
        ->and($handover->reportees($case))->toHaveCount(0);

    // Whatever is left is a warning: completing the exit still works.
    foreach ($case->clearances()->get() as $stage) {
        $this->exits->markNotApplicable($stage, $this->hr, 'n/a');
    }
    $this->exits->complete($case->refresh(), $this->hr, false);
    expect($case->refresh()->status)->toBe('completed');
});

// ---- rehire eligibility -----------------------------------------------------------------------------------------------

it('sets rehire eligibility on open and completed exits, and marks an exited employee not eligible for rehire', function () {
    $blacklist = WorkforceReason::create(['kind' => 'blacklist', 'name' => 'Policy breach', 'status' => 'active']);
    $case = $this->exits->initiate($this->employee, 'resignation', 'Higher studies', '2026-09-21', '2026-09-25', 30, $this->hr);
    expect(fn () => $this->desk->setRehireEligibility($case, false, null, null, $this->hr))->toThrow(RuntimeException::class, 'may not be rehired');
    $this->desk->setRehireEligibility($case, false, $blacklist->id, 'Breach of the code', $this->hr);
    expect($case->refresh()->is_rehire_eligible)->toBeFalse()->and($case->rehire_block_reason_id)->toBe($blacklist->id)->and($case->rehire_comment)->toBe('Breach of the code');
    $this->desk->setRehireEligibility($case, true, null, null, $this->hr);
    expect($case->refresh()->is_rehire_eligible)->toBeTrue()->and($case->rehire_block_reason_id)->toBeNull();

    foreach ($case->clearances()->get() as $stage) {
        $this->exits->markNotApplicable($stage, $this->hr, 'n/a');
    }
    $this->exits->complete($case->refresh(), $this->hr, false);
    $employee = $this->employee->refresh();
    expect($employee->lifecycle_state)->toBe(LifecycleState::Exited);
    $this->desk->markNotEligibleForRehire($employee, $blacklist->id, 'Found later', $this->hr);
    expect($case->refresh()->is_rehire_eligible)->toBeFalse()->and($case->rehire_block_reason_id)->toBe($blacklist->id);
    expect(fn () => $this->desk->markNotEligibleForRehire($this->manager, null, null, $this->hr))->toThrow(RuntimeException::class, 'who has left');
});

// ---- relieving review, tasks ------------------------------------------------------------------------------------------

it('shows the relieving review: last payroll, salary on hold, notice and settlement month, amounts by right', function () {
    $case = $this->exits->initiate($this->employee, 'resignation', 'Higher studies', '2026-09-21', '2026-10-11', 30, $this->hr);
    SalaryHold::create(['employee_id' => $this->employee->id, 'pay_month' => '2026-09', 'reason' => 'Exit', 'status' => 'held']);
    $review = app(RelievingReview::class)->for($case, $this->hr);

    expect($review['last_payroll'])->toBeNull()
        ->and($review['holds'])->toHaveCount(1)->and($review['holds'][0]['pay_month'])->toBe('2026-09')
        ->and($review['notice']['shortfall_days'])->toBe(10)
        ->and($review['settlement_month'])->toBe('October 2026')->and($review['settlement_month_chosen'])->toBeFalse()
        ->and(implode("\n", app(RelievingReview::class)->lines($case, $this->hr)))->toContain('Salary on hold: 2026-09')->toContain('shortfall 10 day(s)');
    Livewire::test(ViewExitCase::class, ['record' => $case->id])->assertOk()->assertSee('Relieving review');
});

it('lists clearance stages for their owners and exits ready to complete for HR in My tasks, behind the switch', function () {
    $case = $this->exits->initiate($this->employee, 'resignation', 'Higher studies', '2026-09-21', '2026-09-25', 30, $this->hr);
    $source = app(ClearanceTaskSource::class);
    expect($source->tasksFor($this->manager->user, $this->manager))->toHaveCount(0);

    sdSet('exit.clearance.my_tasks', true);
    $tasks = $source->tasksFor($this->manager->user, $this->manager);
    expect($tasks)->toHaveCount(1)->and($tasks->first()->title)->toContain('Manager clearance')
        ->and($tasks->first()->dueAt->toDateString())->toBe('2026-09-25')
        ->and(collect(config('peopleos.experience.task_sources')))->toContain(ClearanceTaskSource::class)
        ->and($source->tasksFor($this->employee->user, $this->employee))->toHaveCount(0);

    foreach ($case->clearances()->get() as $stage) {
        $this->exits->markNotApplicable($stage, $this->hr, 'n/a');
    }
    expect($source->tasksFor($this->manager->user, $this->manager))->toHaveCount(0)
        ->and($source->tasksFor($this->hr, null)->where('kind', 'final_clearance')->pluck('reference')->all())->toBe([$case->number]);
    // §23.6.4 #1: the exit cases register's "Ready to complete" view.
    $other = employeeWithUser($this->manager, ['exit.resign']);
    forceLifecycle($other, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $notReady = $this->exits->initiate($other, 'resignation', 'Higher studies', '2026-09-21', '2026-09-25', 30, $this->hr);
    Livewire::withQueryParams(['view' => 'ready_to_complete'])->test(ListExitCases::class)->assertCanSeeTableRecords([$case])->assertCanNotSeeTableRecords([$notReady]);
});
