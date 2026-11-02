<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\RequestTypes\LeaveEncashmentRequestType;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Jobs\ProcessLeaveEncashmentBatch;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveEncashmentBatch;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEncashmentBatches;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\PayrollDesk;
use App\Filament\Resources\LeaveEncashmentBatches\LeaveEncashmentBatchResource;
use App\Filament\Resources\LeaveEncashmentBatches\Pages\ListLeaveEncashmentBatches;
use App\Filament\Resources\LeaveEncashmentBatches\Pages\ViewLeaveEncashmentBatch;
use App\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use App\Filament\Resources\LeaveEncashments\Pages\ListLeaveEncashments;
use App\Filament\Support\LeaveEncashmentWizards;
use App\Filament\Support\PayrollRegisters;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
 * HROne parity PO-8 (matrix 25 §25.6.14 #1–#6; matrix 24 TX-8 "Pay month of the encashment"): HR's "Encash leaves" batches
 * (as per policy or N days, never above what the encashment rule allows; applicability in the access scope; a second
 * person's approval by default; unprocess of what is not paid), the optional pay month honoured by payroll, and HR's
 * "Encash for an employee" request on the employee's behalf.
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();
    $this->maker = tenantUser($this->tenant, ['leave.*', 'payroll.*', 'employee.*']);
    $this->checker = tenantUser($this->tenant, ['leave.*', 'payroll.*', 'employee.*']);
    $this->actingAs($this->maker);
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
    $this->batches = app(LeaveEncashmentBatches::class);

    leavePolicy(['EL' => ['days' => 0, 'encashment_allowed' => true, 'max_encash_days' => 10, 'min_balance_after_encash' => 2], 'SL' => ['days' => 6]]);
    $this->el = LeaveType::query()->where('code', 'EL')->firstOrFail();
    $this->el->update(['is_encashable' => true]);
    $this->sl = LeaveType::query()->where('code', 'SL')->firstOrFail();

    // Asha 12 days, Ravi 5, Kiran 1 (the rule keeps 2).
    $this->asha = salariedEmployee(600000);
    $this->ravi = salariedEmployee(480000);
    $this->kiran = salariedEmployee(360000);
    foreach ([[$this->asha, 12], [$this->ravi, 5], [$this->kiran, 1]] as [$employee, $days]) {
        app(LeaveAdjustments::class)->opening($employee, $this->el, $days, 2026, 'Go-live');
    }
    $this->picked = ['source' => 'employees', 'employee_ids' => [$this->asha->id, $this->ravi->id, $this->kiran->id]];
});

function po8Available(Employee $employee, LeaveType $type): float
{
    return round(app(LeaveBalances::class)->balance($employee, $type, 2026)->available(), 2);
}

/** @return array<int, float> employee id => days of the batch's encashments */
function po8Days(LeaveEncashmentBatch $batch): array
{
    return LeaveEncashment::query()->where('leave_encashment_batch_id', $batch->id)->pluck('days', 'employee_id')->map(fn ($d) => (float) $d)->all();
}

function po8Line(int $runId, int $employeeId): ?PayrollEntryLine
{
    return PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $runId)->where('employee_id', $employeeId))->where('code', 'LEAVE_ENC')->first();
}

function po8Approve(LeaveEncashmentBatch $batch): LeaveEncashmentBatch
{
    test()->actingAs(test()->checker);
    $batch = app(LeaveEncashmentBatches::class)->approve($batch, test()->checker, 'Year end');
    test()->actingAs(test()->maker);

    return $batch;
}

it('encashes as per policy up to what the rule allows now, or the days specified capped at that, and says why people are skipped', function () {
    $policy = $this->batches->preview($this->el, 'policy', null, $this->picked, $this->maker);
    $lines = collect($policy['lines'])->keyBy('employee_id');
    expect($lines[$this->asha->id]['days'])->toBe(10.0)            // 12 less the 2 to keep, within 10 a year
        ->and($lines[$this->ravi->id]['days'])->toBe(3.0)           // 5 less 2
        ->and($lines->has($this->kiran->id))->toBeFalse()
        ->and($policy['skipped'][0]['employee_id'])->toBe($this->kiran->id)
        ->and($policy['skipped'][0]['reason'])->toBe('The rule keeps a balance of 2 days and only 1 day is available.')
        ->and($policy['period'])->toBe(2026);

    $specified = collect($this->batches->preview($this->el, 'specified', 4, $this->picked, $this->maker)['lines'])->keyBy('employee_id');
    expect($specified[$this->asha->id]['days'])->toBe(4.0)->and($specified[$this->asha->id]['note'])->toBeNull()
        ->and($specified[$this->ravi->id]['days'])->toBe(3.0)
        ->and($specified[$this->ravi->id]['note'])->toBe('Capped at 3 days: the most the rule allows now');

    // A pending request is kept back from the balance and counts against the year.
    app(LeaveAdjustments::class)->requestEncashment($this->asha, $this->el, 6, 'Holiday');
    expect(collect($this->batches->preview($this->el, 'policy', null, $this->picked, $this->maker)['lines'])->firstWhere('employee_id', $this->asha->id)['days'])->toBe(4.0)
        ->and(fn () => $this->batches->preview($this->sl, 'policy', null, $this->picked, $this->maker))->toThrow(RuntimeException::class, 'Sick leave cannot be encashed.')
        ->and(fn () => $this->batches->preview($this->el, 'specified', 0.3, $this->picked, $this->maker))->toThrow(RuntimeException::class, 'in half days')
        ->and(fn () => $this->batches->preview($this->el, 'policy', null, ['source' => 'employees', 'employee_ids' => []], $this->maker))->toThrow(RuntimeException::class, 'Search and add at least one employee.');

    // Processed (approval off), the specified days are taken off the balances as reviewed.
    app(SettingsRepository::class)->set(LeaveEncashmentBatches::APPROVAL_SETTING, false, 'One person');
    $batch = $this->batches->submit($this->el, 'specified', 2, null, $this->picked, 'Year end', $this->maker);
    expect($batch->status)->toBe('processed')->and(po8Days($batch))->toEqual([$this->asha->id => 2.0, $this->ravi->id => 2.0])
        ->and($batch->encashed_count)->toBe(2)->and((float) $batch->encashed_days)->toBe(4.0)
        ->and(po8Available($this->asha, $this->el))->toBe(10.0)->and(po8Available($this->ravi, $this->el))->toBe(3.0)->and(po8Available($this->kiran, $this->el))->toBe(1.0)
        ->and(LeaveEncashmentBatchResource::skipped($batch)[0])->toMatchArray(['employee_id' => $this->kiran->id, 'when' => 'At review']);
    $encashment = LeaveEncashment::query()->where('leave_encashment_batch_id', $batch->id)->where('employee_id', $this->asha->id)->firstOrFail();
    expect($encashment->status)->toBe('approved')->and($encashment->requested_by)->toBe($this->maker->id)->and($encashment->reviewed_by)->toBe($this->maker->id)
        ->and($encashment->review_note)->toBe('Encashment batch #'.$batch->id)->and($encashment->pay_month)->toBeNull();

    // Never more than the rule allows at processing time, never twice for the same batch.
    $this->batches->process($batch);
    expect(LeaveEncashment::query()->where('leave_encashment_batch_id', $batch->id)->count())->toBe(2);
});

it('skips the person making the batch and people not employed, and caps a line again when processed later', function () {
    $hr = employeeWithUser(null, ['leave.*', 'payroll.*', 'employee.*']);
    forceLifecycle($hr, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    app(LeaveAdjustments::class)->opening($hr, $this->el, 10, 2026, 'Go-live');
    forceLifecycle($this->kiran, LifecycleState::Exited, ['joining_date' => '2025-01-01']);
    $this->actingAs($hr->user);

    $review = $this->batches->preview($this->el, 'policy', null, ['source' => 'employees', 'employee_ids' => [$hr->id, $this->asha->id, $this->kiran->id]], $hr->user);
    expect(collect($review['lines'])->pluck('employee_id')->all())->toBe([$this->asha->id])
        ->and(collect($review['skipped'])->pluck('reason', 'employee_id')->all())->toEqual([$hr->id => 'Your own encashment: ask for it in My encashments.', $this->kiran->id => 'Not employed now.']);

    // Reviewed at 10 days; Asha uses 9 days of balance before the approval: only what is left (12 - 9 - 2 = 1) is encashed.
    $batch = $this->batches->submit($this->el, 'policy', null, '2026-10', ['source' => 'employees', 'employee_ids' => [$this->asha->id]], null, $hr->user);
    app(LeaveAdjustments::class)->adjust($this->asha, $this->el, -9, 'Correction', 2026);
    po8Approve($batch);
    expect(po8Days($batch->refresh()))->toEqual([$this->asha->id => 1.0])->and($batch->status)->toBe('processed')
        ->and(LeaveEncashment::query()->where('leave_encashment_batch_id', $batch->id)->value('pay_month'))->toBe('2026-10');
});

it('chooses employees only in the access scope, by search, organisation filters or pasted codes', function () {
    $other = Company::factory()->create(['name' => 'Beta']);
    $beta = app(HireEmployeeAction::class)->handle(['first_name' => 'Bina', 'last_name' => 'Test'], ['joining_date' => '2025-01-01'], ['company_id' => $other->id]);
    forceLifecycle($beta, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    app(LeaveAdjustments::class)->opening($beta, $this->el, 8, 2026, 'Go-live');

    // Unscoped HR reaches everyone, through any source.
    expect(collect($this->batches->preview($this->el, 'policy', null, ['source' => 'filters', 'everyone' => true], $this->maker)['lines'])->pluck('employee_id')->sort()->values()->all())
        ->toEqual(collect([$this->asha->id, $this->ravi->id, $beta->id])->sort()->values()->all())
        ->and(collect($this->batches->preview($this->el, 'policy', null, ['source' => 'filters', 'company_id' => [$other->id]], $this->maker)['lines'])->pluck('employee_id')->all())->toBe([$beta->id])
        ->and(collect($this->batches->preview($this->el, 'policy', null, ['source' => 'codes', 'codes' => $beta->employee_code.', '.$this->ravi->employee_code], $this->maker)['lines'])->pluck('employee_id')->sort()->values()->all())
        ->toEqual(collect([$this->ravi->id, $beta->id])->sort()->values()->all())
        ->and(fn () => $this->batches->preview($this->el, 'policy', null, ['source' => 'filters'], $this->maker))->toThrow(RuntimeException::class, 'Choose at least one filter');

    // HR scoped to the first company never reaches Beta's employee, whichever way it is chosen.
    $scoped = tenantUser($this->tenant, ['leave.*', 'payroll.*', 'employee.*']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$this->company->id]]);
    $this->actingAs($scoped);
    foreach ([['source' => 'filters', 'everyone' => true], ['source' => 'codes', 'codes' => $beta->employee_code.' '.$this->asha->employee_code], ['source' => 'employees', 'employee_ids' => [$beta->id, $this->asha->id]]] as $who) {
        expect(collect($this->batches->preview($this->el, 'policy', null, $who, $scoped)['lines'])->pluck('employee_id')->all())->not->toContain($beta->id);
    }
    expect(fn () => $this->batches->preview($this->el, 'policy', null, ['source' => 'codes', 'codes' => $beta->employee_code], $scoped))->toThrow(RuntimeException::class, 'Nobody matches');

    // A batch over both companies is approved only by someone who reaches every employee in it.
    $this->actingAs($this->maker);
    $batch = $this->batches->submit($this->el, 'policy', null, null, ['source' => 'filters', 'everyone' => true], null, $this->maker);
    expect($this->batches->approvalRefusal($batch, $scoped))->toBe('1 employee(s) of this batch are outside your access: someone who can reach all of them approves it.')
        ->and(collect(app(ApprovalCenter::class)->pending($scoped))->where('type', 'leave_encashment_batch'))->toHaveCount(0)
        ->and(fn () => $this->batches->approve($batch, $scoped))->toThrow(RuntimeException::class, 'outside your access');
    expect(LeaveEncashment::query()->count())->toBe(0);
});

it('waits for another person\'s approval by default, in the Approval Center or on the batch, before any encashment is made', function () {
    expect(config('peopleos.settings')[LeaveEncashmentBatches::APPROVAL_SETTING])->toBeTrue()->and($this->batches->approvalRequired())->toBeTrue();

    $batch = $this->batches->submit($this->el, 'policy', null, null, $this->picked, 'Year end', $this->maker);
    expect($batch->status)->toBe('pending')->and($batch->employee_count)->toBe(2)->and(LeaveEncashment::query()->count())->toBe(0)
        ->and(po8Available($this->asha, $this->el))->toBe(12.0)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', $batch->getMorphClass())->where('entity_id', $batch->id)->where('action', 'SUBMITTED')->exists())->toBeTrue();

    // Not the person who made it, not someone in it, not someone without the right.
    expect($this->batches->approvalRefusal($batch, $this->maker))->toBe('You made this batch: someone else approves it.')
        ->and(fn () => $this->batches->approve($batch, $this->maker))->toThrow(RuntimeException::class, 'You made this batch')
        ->and($this->batches->approvalRefusal($batch, $this->asha->user))->toBe('Approving an encashment batch needs the right to manage leave.');
    $inBatch = tenantUser($this->tenant, ['leave.*']);
    $raviUser = $this->ravi->user_id;
    $this->ravi->update(['user_id' => $inBatch->id]);
    expect($this->batches->approvalRefusal($batch, $inBatch))->toBe('You are in this batch: someone else approves it.');
    $this->ravi->update(['user_id' => $raviUser]);
    expect(collect(app(ApprovalCenter::class)->pending($this->maker))->where('type', 'leave_encashment_batch'))->toHaveCount(0)
        ->and(ApprovalCenter::kinds())->toMatchArray(['leave_encashment_batch' => 'Leave encashment batch']);

    // The checker sees it in the Approval Center and approves it there: the batch is processed in their name.
    $this->actingAs($this->checker);
    $item = collect(app(ApprovalCenter::class)->pending($this->checker))->firstWhere('id', 'leave_encashment_batch:'.$batch->id);
    expect($item)->not->toBeNull()->and($item->title)->toBe('Encash Earned leave for 2 employee(s) · As per policy')->and($item->requestedBy)->toBe($this->maker->name)
        ->and($item->facts)->toContain('1 employee(s) skipped at review');
    expect(app(ApprovalDecisions::class)->decide($this->checker, $item->id, 'approve', 'Checked'))->toBe('Encashment batch approved');
    $batch->refresh();
    expect($batch->status)->toBe('processed')->and($batch->approved_by)->toBe($this->checker->id)->and($batch->decision_note)->toBe('Checked')
        ->and(po8Days($batch))->toEqual([$this->asha->id => 10.0, $this->ravi->id => 3.0])
        ->and(LeaveEncashment::query()->where('leave_encashment_batch_id', $batch->id)->pluck('reviewed_by')->unique()->all())->toBe([$this->checker->id])
        ->and(po8Available($this->asha, $this->el))->toBe(2.0)
        ->and(collect(app(ApprovalCenter::class)->pending($this->checker))->where('type', 'leave_encashment_batch'))->toHaveCount(0);

    // Rejected or withdrawn: nothing is ever encashed.
    $this->actingAs($this->maker);
    app(LeaveAdjustments::class)->adjust($this->ravi, $this->el, 5, 'Credit', 2026);
    $other = $this->batches->submit($this->el, 'specified', 1, null, ['source' => 'employees', 'employee_ids' => [$this->ravi->id, $this->asha->id]], null, $this->maker);
    expect(fn () => $this->batches->reject($other, $this->maker, 'No'))->toThrow(RuntimeException::class, 'withdraw it instead')
        ->and(fn () => $this->batches->reject($other, $this->checker, ' '))->toThrow(RuntimeException::class, 'Give a reason');
    expect($this->batches->reject($other, $this->checker, 'Not this month')->status)->toBe('rejected')
        ->and(fn () => $this->batches->approve($other, $this->checker))->toThrow(RuntimeException::class, 'no longer waiting');
    $third = $this->batches->submit($this->el, 'specified', 1, null, ['source' => 'employees', 'employee_ids' => [$this->ravi->id]], null, $this->maker);
    expect($this->batches->withdraw($third, $this->maker, 'Made by mistake')->status)->toBe('withdrawn')
        ->and(LeaveEncashment::query()->whereIn('leave_encashment_batch_id', [$other->id, $third->id])->count())->toBe(0);

    // Someone without leave.manage makes no batch.
    expect(fn () => $this->batches->submit($this->el, 'policy', null, null, $this->picked, null, $this->asha->user))->toThrow(AuthorizationException::class);
});

it('queues the processing per batch and tenant once approved', function () {
    Queue::fake();
    $batch = $this->batches->submit($this->el, 'policy', null, null, $this->picked, null, $this->maker);
    Queue::assertNothingPushed();
    $this->actingAs($this->checker);
    $this->batches->approve($batch, $this->checker);
    Queue::assertPushed(ProcessLeaveEncashmentBatch::class, fn (ProcessLeaveEncashmentBatch $job) => $job->batchId === $batch->id && $job->tenantId === $this->tenant->id);
    expect($batch->refresh()->status)->toBe('processing')->and(LeaveEncashment::query()->count())->toBe(0);

    (new ProcessLeaveEncashmentBatch($batch->id))->handle($this->batches);
    expect($batch->refresh()->status)->toBe('processed')->and(LeaveEncashment::query()->count())->toBe(2)
        ->and((new ProcessLeaveEncashmentBatch($batch->id))->tries)->toBe(3);
    (new ProcessLeaveEncashmentBatch($batch->id))->handle($this->batches); // processed batches are left alone
    expect(LeaveEncashment::query()->count())->toBe(2);
});

it('honours the pay month on payroll, keeps null as the next run exactly as before, and leaves the amount formula unchanged', function () {
    // A request approved today with no pay month: paid by September, as before.
    $request = app(LeaveAdjustments::class)->requestEncashment($this->ravi, $this->el, 1, 'Festival');
    app(LeaveAdjustments::class)->approveEncashment($request);
    app(SettingsRepository::class)->set(LeaveEncashmentBatches::APPROVAL_SETTING, false, 'One person');
    $october = $this->batches->submit($this->el, 'specified', 3, '2026-10', ['source' => 'employees', 'employee_ids' => [$this->asha->id]], null, $this->maker);
    $august = $this->batches->submit($this->el, 'specified', 1, '2026-08', ['source' => 'employees', 'employee_ids' => [$this->asha->id]], null, $this->maker);
    expect(fn () => $this->batches->submit($this->el, 'policy', null, 'Oct 26', $this->picked, null, $this->maker))->toThrow(RuntimeException::class, 'YYYY-MM');

    $september = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->maker), $this->maker);
    $ravi = po8Line($september->id, $this->ravi->id);
    $basic = PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $september->id)->where('employee_id', $this->ravi->id))->where('classification', 'basic')->first();
    expect($ravi)->not->toBeNull()->and($ravi->name)->toBe('Earned leave encashment (1 days)')
        ->and((float) $ravi->amount)->toBe(round(1 * round((float) $basic->basis['full_month'] / 30, 2), 2))
        ->and(array_keys($ravi->basis))->not->toContain('pay_month')
        ->and($ravi->basis['source'])->toEqual(['key' => 'leave_encashment', 'ids' => [$request->id]]);
    // Asha: only the August encashment (missed month) is paid in September; October's waits.
    $asha = po8Line($september->id, $this->asha->id);
    $augustEncashment = LeaveEncashment::query()->where('leave_encashment_batch_id', $august->id)->firstOrFail();
    expect($asha->name)->toBe('Earned leave encashment (1 days) for 2026-08')->and($asha->basis['pay_month'])->toBe('2026-08')
        ->and($asha->basis['source']['ids'])->toBe([$augustEncashment->id]);

    $this->runs->finalize($this->runs->approve($this->runs->validate($september), $this->checker, 'ok'), $this->checker);
    $octoberEncashment = LeaveEncashment::query()->where('leave_encashment_batch_id', $october->id)->firstOrFail();
    expect($request->refresh()->status)->toBe('paid')->and($augustEncashment->refresh()->status)->toBe('paid')->and($octoberEncashment->refresh()->status)->toBe('approved');

    $this->travelTo('2026-10-20 09:00:00');
    $octoberRun = $this->runs->calculate($this->runs->open($this->company, 2026, 10, $this->maker), $this->maker);
    $line = po8Line($octoberRun->id, $this->asha->id);
    expect($line)->not->toBeNull()->and($line->name)->toBe('Earned leave encashment (3 days)')->and($line->basis['source']['ids'])->toBe([$octoberEncashment->id])
        ->and(po8Line($octoberRun->id, $this->ravi->id))->toBeNull();

    // HR may set the pay month when approving a request; blank keeps it as asked.
    $later = app(LeaveAdjustments::class)->requestEncashment($this->ravi, $this->el, 0.5, null, payMonth: '2026-12');
    expect($later->pay_month)->toBe('2026-12');
    expect(app(LeaveAdjustments::class)->approveEncashment($later)->refresh()->pay_month)->toBe('2026-12');
    $other = app(LeaveAdjustments::class)->requestEncashment($this->ravi, $this->el, 0.5);
    expect(app(LeaveAdjustments::class)->approveEncashment($other, 'ok', '2026-11')->refresh()->pay_month)->toBe('2026-11');
});

it('unprocesses only what no finalized payroll has paid and no open run carries, for all employees or the ones chosen', function () {
    app(SettingsRepository::class)->set(LeaveEncashmentBatches::APPROVAL_SETTING, false, 'One person');
    $now = $this->batches->submit($this->el, 'specified', 2, null, $this->picked, null, $this->maker);
    $later = $this->batches->submit($this->el, 'specified', 1, '2026-10', $this->picked, null, $this->maker);
    expect(po8Available($this->asha, $this->el))->toBe(9.0);

    // September is calculated (open): it carries this month's batch, which stays.
    $september = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->maker), $this->maker);
    $out = $this->batches->unprocess($now, $this->maker, [$this->ravi->id], 'Wrong month');
    expect($out['cancelled'])->toBe(0)->and($out['kept'][0]['reason'])->toBe('Being paid by the payroll of Sep 2026: it stays.')
        ->and($now->refresh()->status)->toBe('processed');

    // Finalized: paid encashments stay.
    $this->runs->finalize($this->runs->approve($this->runs->validate($september), $this->checker, 'ok'), $this->checker);
    $out = $this->batches->unprocess($now, $this->maker, null, 'Undo');
    expect($out['cancelled'])->toBe(0)->and(collect($out['kept'])->pluck('reason')->unique()->all())->toBe(['Already paid: it stays.'])
        ->and(LeaveEncashment::query()->where('leave_encashment_batch_id', $now->id)->pluck('status')->unique()->all())->toBe(['paid']);

    // October's batch is neither paid nor carried: unprocessed for one employee, then for all.
    $out = $this->batches->unprocess($later, $this->maker, [$this->asha->id], 'Asha keeps her leave');
    expect($out['cancelled'])->toBe(1)->and(po8Available($this->asha, $this->el))->toBe(10.0)
        ->and(LeaveEncashment::query()->where('leave_encashment_batch_id', $later->id)->where('employee_id', $this->asha->id)->value('status'))->toBe('cancelled')
        ->and($later->refresh()->status)->toBe('processed')->and($later->unprocessed_by)->toBe($this->maker->id);
    $out = $this->batches->unprocess($later, $this->maker, null, 'Not needed');
    expect($out['cancelled'])->toBe(1)->and($later->refresh()->status)->toBe('unprocessed')->and($later->unprocess_reason)->toBe('Not needed')
        ->and(LeaveEncashment::query()->where('leave_encashment_batch_id', $later->id)->where('employee_id', $this->ravi->id)->value('cancel_reason'))
        ->toBe('Encashment batch #'.$later->id.' unprocessed: Not needed')
        ->and(fn () => $this->batches->unprocess($later, $this->maker, null, 'Again'))->toThrow(RuntimeException::class, 'Only a processed batch')
        ->and(fn () => $this->batches->unprocess($now, $this->maker, null, ' '))->toThrow(RuntimeException::class, 'Give a reason')
        ->and(fn () => $this->batches->unprocess($now, $this->asha->user, null, 'x'))->toThrow(AuthorizationException::class);

    // Each cancellation is audited under one bulk operation.
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', 'BULK_OPERATION')->get()->pluck('metadata.label')->all())->toContain('Encashment batch #'.$later->id.' unprocessed');
});

it('lets HR raise an encashment request for an employee in scope, never for themselves, checked and approved like the employee\'s own', function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    Livewire::test(ListLeaveEncashments::class)->assertActionVisible('encashForEmployee')->assertActionVisible('encashLeavesBatch')
        ->callAction('encashForEmployee', ['employee_id' => $this->asha->id, 'leave_type_id' => $this->el->id, 'days' => 3, 'pay_month' => '2026-11', 'reason' => 'Asked by phone'])
        ->assertHasNoActionErrors();
    $encashment = LeaveEncashment::query()->where('employee_id', $this->asha->id)->latest('id')->firstOrFail();
    expect($encashment->status)->toBe('pending')->and($encashment->requested_by)->toBe($this->maker->id)->and($encashment->pay_month)->toBe('2026-11')
        ->and((float) $encashment->days)->toBe(3.0)->and($encashment->leave_encashment_batch_id)->toBeNull();

    // The same rule as the employee's own: balance and the balance to keep.
    Livewire::test(ListLeaveEncashments::class)->callAction('encashForEmployee', ['employee_id' => $this->ravi->id, 'leave_type_id' => $this->el->id, 'days' => 4])->assertNotified('Not sent');
    expect(LeaveEncashment::query()->where('employee_id', $this->ravi->id)->exists())->toBeFalse();

    // A supporting file when the rule asks for one (a policy taking precedence over the standard one).
    $files = leavePolicy(['EL' => ['days' => 0, 'encashment_allowed' => true, 'max_encash_days' => 10, 'encash_document_required' => true]], code: 'PO8_FILES');
    PolicyAssignmentRule::query()->where('policy_id', $files->id)->update(['priority' => 1]);
    Livewire::test(ListLeaveEncashments::class)->callAction('encashForEmployee', ['employee_id' => $this->ravi->id, 'leave_type_id' => $this->el->id, 'days' => 1])
        ->assertHasActionErrors(['attachments' => 'required']);
    expect(fn () => app(LeaveAdjustments::class)->requestEncashment($this->ravi, $this->el, 1))->toThrow(RuntimeException::class, 'Attach a supporting file');
    PolicyAssignmentRule::query()->where('policy_id', $files->id)->update(['priority' => 1000]);

    // Never for oneself, never out of scope, never without leave.manage.
    $hr = employeeWithUser(null, ['leave.*', 'payroll.*', 'employee.*']);
    $this->actingAs($hr->user);
    Livewire::test(ListLeaveEncashments::class)->callAction('encashForEmployee', ['employee_id' => $hr->id, 'leave_type_id' => $this->el->id, 'days' => 1])->assertNotified('Not sent');
    expect(LeaveEncashment::query()->where('employee_id', $hr->id)->exists())->toBeFalse();
    $scoped = tenantUser($this->tenant, ['leave.*', 'employee.*']);
    app(AccessScopes::class)->assign($scoped, ['company' => [Company::factory()->create()->id]]);
    $this->actingAs($scoped);
    Livewire::test(ListLeaveEncashments::class)->callAction('encashForEmployee', ['employee_id' => $this->ravi->id, 'leave_type_id' => $this->el->id, 'days' => 1]);
    expect(LeaveEncashment::query()->withoutGlobalScopes()->where('employee_id', $this->ravi->id)->exists())->toBeFalse()
        ->and(fn () => LeaveEncashmentWizards::resolve($this->ravi->id))->toThrow(RuntimeException::class, 'Choose an employee in your access scope.')
        ->and(fn () => LeaveEncashmentWizards::resolve($this->asha->id))->toThrow(RuntimeException::class, 'Choose an employee in your access scope.');
    $this->actingAs($hr->user);
    expect(fn () => LeaveEncashmentWizards::resolve($hr->id))->toThrow(RuntimeException::class, 'This is your own record: use "Request encashment" in My encashments.');
    $this->actingAs(tenantUser($this->tenant, ['leave.view', 'leave.approve']));
    expect(fn () => LeaveEncashmentWizards::resolve($this->ravi->id))->toThrow(RuntimeException::class, 'needs the right to manage leave');
    Livewire::test(ListLeaveEncashments::class)->assertActionHidden('encashForEmployee')->assertActionHidden('encashLeavesBatch');

    // It waits for approval like the employee's own: the Approval Center lists it for HR.
    $this->actingAs($this->checker);
    $item = collect(app(ApprovalCenter::class)->pending($this->checker))->firstWhere('id', 'leave_encashment:'.$encashment->id);
    expect($item)->not->toBeNull();
    app(ApprovalDecisions::class)->decide($this->checker, $item->id, 'approve', 'ok');
    expect($encashment->refresh()->status)->toBe('approved')->and($encashment->pay_month)->toBe('2026-11')->and(po8Available($this->asha, $this->el))->toBe(9.0);

    // The request registry offers it on behalf to people who manage leave, and opens the form directly.
    $type = app(LeaveEncashmentRequestType::class);
    expect($type->onBehalfPermission())->toBe('leave.manage')
        ->and($type->onBehalfUrl($this->checker))->toBe(LeaveEncashmentResource::getUrl('index').'?action=encashForEmployee')
        ->and(collect(app(RequestRegistry::class)->onBehalf($this->checker))->map(fn (array $e) => $e['type']->key())->all())->toContain('leave_encashment');
    $plain = tenantUser($this->tenant, ['leave.view', 'leave.apply']);
    $this->actingAs($plain);
    expect($type->onBehalfUrl($plain))->toBeNull();
});

it('offers the batch screen, its wizard, filters, view and unprocess, on the payroll desk and in the payroll actions menu', function () {
    expect(LeaveEncashmentBatchResource::getUrl('index', ['action' => 'encashLeaves']))->toContain('leave-encashment-batches?action=encashLeaves');
    $this->get(LeaveEncashmentBatchResource::getUrl('index'))->assertOk()->assertSee('Encash leaves');

    Livewire::test(ListLeaveEncashmentBatches::class)->assertActionVisible('encashLeaves')
        ->mountAction('encashLeaves')
        ->fillForm(['leave_type_id' => $this->el->id, 'mode' => 'specified', 'specified_days' => 4, 'source' => 'employees', 'employee_ids' => [$this->asha->id, $this->ravi->id, $this->kiran->id], 'pay_month' => '2026-10'])
        ->assertMountedActionModalSee('Capped at 3 days: the most the rule allows now')
        ->assertMountedActionModalSee('The rule keeps a balance of 2 days and only 1 day is available.')
        ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Encashment batch sent for approval');
    $batch = LeaveEncashmentBatch::query()->latest('id')->firstOrFail();
    expect($batch->status)->toBe('pending')->and($batch->pay_month)->toBe('2026-10')->and((float) $batch->specified_days)->toBe(4.0)
        ->and($batch->applicability)->toEqual(['source' => 'employees', 'employee_ids' => [$this->asha->id, $this->ravi->id, $this->kiran->id]])
        ->and(LeaveEncashmentBatchResource::getNavigationBadge())->toBe('1');

    // The register: HROne's filters (encashment date, encashed by, pay month).
    Livewire::test(ListLeaveEncashmentBatches::class)->assertCanSeeTableRecords([$batch])
        ->filterTable('pay_month', '2026-11')->assertCanNotSeeTableRecords([$batch])
        ->resetTableFilters()->filterTable('created_by', $this->checker->id)->assertCanNotSeeTableRecords([$batch])
        ->resetTableFilters()->filterTable('created_by', $this->maker->id)->assertCanSeeTableRecords([$batch])
        ->assertTableActionHidden('approveBatch', $batch);

    // The checker approves it on the batch page, then unprocesses it there.
    $this->actingAs($this->checker);
    Livewire::test(ViewLeaveEncashmentBatch::class, ['record' => $batch->getRouteKey()])->assertActionVisible('approveBatch')->assertActionHidden('unprocessBatch')
        ->callAction('approveBatch', ['note' => 'Fine'])->assertNotified('Encashment batch approved');
    expect($batch->refresh()->status)->toBe('processed')->and(po8Days($batch))->toEqual([$this->asha->id => 4.0, $this->ravi->id => 3.0]);
    Livewire::test(ListLeaveEncashmentBatches::class)->filterTable('encashment_dates', ['from' => '2026-09-21', 'until' => '2026-09-21'])->assertCanSeeTableRecords([$batch])
        ->resetTableFilters()->filterTable('encashment_dates', ['from' => '2026-09-22'])->assertCanNotSeeTableRecords([$batch]);
    $this->get(LeaveEncashmentBatchResource::getUrl('view', ['record' => $batch]))->assertOk()->assertSee('Earned leave')->assertSee('Oct 2026')->assertSee('Unprocess');
    Livewire::test(ViewLeaveEncashmentBatch::class, ['record' => $batch->getRouteKey()])
        ->callAction('unprocessBatch', ['who' => 'some', 'employee_ids' => [$this->ravi->id], 'reason' => 'Ravi keeps it'])->assertNotified('1 encashment(s) cancelled');
    expect(LeaveEncashment::query()->where('leave_encashment_batch_id', $batch->id)->pluck('status', 'employee_id')->all())->toEqual([$this->asha->id => 'approved', $this->ravi->id => 'cancelled']);

    // Leave viewers see the batches; only leave.manage makes them.
    $this->actingAs(tenantUser($this->tenant, ['leave.view', 'payroll.view', 'payroll.calculate']));
    expect(LeaveEncashmentBatchResource::canAccess())->toBeTrue();
    Livewire::test(ListLeaveEncashmentBatches::class)->assertActionHidden('encashLeaves');
    $this->actingAs(tenantUser($this->tenant, ['payroll.view', 'payroll.calculate']));
    expect(LeaveEncashmentBatchResource::canAccess())->toBeFalse();
    $this->get(LeaveEncashmentBatchResource::getUrl('index'))->assertForbidden();

    // Payroll desk entry and the payroll actions menu.
    $this->actingAs($this->maker);
    $item = collect(collect((new PayrollDesk)->deskSections())->firstWhere('label', 'Payroll processing')['items'])->firstWhere('label', 'Encash leaves');
    expect($item)->not->toBeNull()->and($item['url'])->toBe(LeaveEncashmentBatchResource::getUrl('index'));
    $actions = collect(PayrollRegisters::actionsMenu()->getActions())->filter(fn (Action $a) => $a->isVisible())->map(fn (Action $a) => $a->getName())->values()->all();
    expect($actions)->toContain('posPayEncashLeaves');
});

it('classifies the new table and keeps the setting under Setup → Payroll', function () {
    expect(DataInventory::TABLES['leave'])->toContain('leave_encashment_batches')
        ->and(collect(config('peopleos_setup.modules.payroll.settings'))->pluck('key')->all())->toContain(LeaveEncashmentBatches::APPROVAL_SETTING)
        ->and(User::query()->count())->toBeGreaterThan(0);
});
