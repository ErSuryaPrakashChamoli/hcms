<?php

namespace App\Domain\Leave\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeSelection;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Contracts\EncashmentsBeingPaid;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Leave\Jobs\ProcessLeaveEncashmentBatch;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveEncashmentBatch;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\PayMonth;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Support\DelegatedDecision;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne parity PO-8 (matrix 25 §25.6.14 #1–#6, "Encash leaves"): HR encashes one leave type for many employees.
 *
 * - Who (`employees()`): picked employees, the WO-2 organisation / location filters or pasted codes (EmployeeSelection),
 *   always through the scoped Employee query — nobody outside the person's access scope is ever chosen.
 * - How many days (`preview()`): "as per policy" = the most the leave type's encashment rule allows now (TO-7, from
 *   LeaveAdjustments::encashmentAllowance: the balance less the balance to keep, the days a year left, the most in one
 *   request) less the days of encashment requests still pending; "specify" = that number, capped at the same most. People
 *   with nothing to encash, not employed, or the person themselves are skipped with the reason.
 * - Approval: with `payroll.encashment.batch_approval` on (the default) the batch waits (`pending`) for someone else with
 *   leave.manage who can reach every employee in it and is not in it; that person approves it here, on the batch page or
 *   in the Approval Center (LeaveEncashmentBatchApprovalSource). Off: it is processed at once in the name of who made it.
 * - Processing (`process()`, queued: ProcessLeaveEncashmentBatch): each reviewed line becomes an approved encashment of the
 *   batch (never more days than reviewed, and never more than the rule allows at that moment), its days taken off the
 *   balance through the ledger, in its own transaction, so one refusal never stops the others. Paid like any approved
 *   encashment: in the pay run of the batch's pay month (or a later one), or the next run when there is none.
 * - Unprocess (`unprocess()`): cancels the batch's encashments not paid yet (days back to the balance, as a cancellation
 *   always does); paid ones and ones a pay run being prepared already takes stay, with the reason.
 */
final class LeaveEncashmentBatches
{
    /** Setup → Payroll: whether a batch needs a second person's approval before it is processed (default on). */
    public const APPROVAL_SETTING = 'payroll.encashment.batch_approval';

    /** HROne's applicability: "Search Employees", "Organization" / "Location", "Add bulk employees". */
    public const SOURCES = ['employees' => 'Search employees', 'filters' => 'Organisation and location', 'codes' => 'Add bulk employees (paste their codes)'];

    /** The most employees one batch takes (narrow the choice for more). */
    public const MAX_EMPLOYEES = 5000;

    public function __construct(
        private readonly LeaveAdjustments $adjustments,
        private readonly LeaveBalances $balances,
        private readonly LeaveYear $years,
        private readonly AuditRecorder $audit,
        private readonly SettingsRepository $settings,
        private readonly AccessScopes $scopes,
        private readonly EncashmentsBeingPaid $payments,
    ) {}

    public function approvalRequired(): bool
    {
        return (bool) $this->settings->get(self::APPROVAL_SETTING, true);
    }

    /** Whether the person may make, approve and unprocess batches. */
    public function mayManage(?User $user): bool
    {
        return $user !== null && $user->hasPermission('leave.manage');
    }

    /**
     * The employees a batch's "who" chooses (scoped Employee query).
     *
     * @param  array<string, mixed>  $who  source (employees | filters | codes) and its criteria
     */
    public function employees(array $who): Builder
    {
        return match ((string) ($who['source'] ?? '')) {
            'employees' => ($ids = array_values(array_filter(array_map('intval', (array) ($who['employee_ids'] ?? []))))) === []
                ? throw new RuntimeException('Search and add at least one employee.')
                : Employee::query()->whereIn('employees.id', $ids),
            'filters' => app(EmployeeSelection::class)->query(['source' => 'filters'] + $who),
            'codes' => app(EmployeeSelection::class)->query(['source' => 'codes', 'codes' => $who['codes'] ?? null]),
            default => throw new RuntimeException('Choose who is included: search employees, organisation and location, or paste codes.'),
        };
    }

    /**
     * The review: days per employee and who is skipped, with the reason.
     *
     * @param  array<string, mixed>  $who
     * @return array{lines: list<array{employee_id: int, label: string, days: float, note: ?string}>, skipped: list<array{employee_id: int, label: string, reason: string}>, period: int}
     */
    public function preview(LeaveType $type, string $mode, ?float $days, array $who, User $actor, ?int $period = null): array
    {
        return $this->as($actor, fn () => $this->review($type, $mode, $days, $who, $actor, $period));
    }

    /**
     * @param  array<string, mixed>  $who
     * @return array{lines: list<array{employee_id: int, label: string, days: float, note: ?string}>, skipped: list<array{employee_id: int, label: string, reason: string}>, period: int}
     */
    private function review(LeaveType $type, string $mode, ?float $days, array $who, User $actor, ?int $period): array
    {
        $this->assertTerms($type, $mode, $days);
        $period ??= $this->years->periodFor(now());
        $ids = $this->employees($who)->orderBy('employees.id')->limit(self::MAX_EMPLOYEES + 1)->pluck('employees.id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            throw new RuntimeException('Nobody matches: change who is included.');
        }
        if (count($ids) > self::MAX_EMPLOYEES) {
            throw new RuntimeException('More than '.self::MAX_EMPLOYEES.' employees match: narrow who is included.');
        }

        $lines = [];
        $skipped = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Employee::query()->with('person')->whereIn('employees.id', $chunk)->orderBy('employee_code')->get() as $employee) {
                $label = $employee->auditLabel();
                $reason = $this->refusal($employee, $actor);
                $most = $reason === null ? $this->most($employee, $type, $period) : null;
                if ($reason === null && $most['days'] <= 0) {
                    $reason = $most['reason'];
                }
                if ($reason !== null) {
                    $skipped[] = ['employee_id' => (int) $employee->getKey(), 'label' => $label, 'reason' => $reason];

                    continue;
                }
                $take = $mode === 'specified' ? min((float) $days, $most['days']) : $most['days'];
                $lines[] = ['employee_id' => (int) $employee->getKey(), 'label' => $label, 'days' => $take,
                    'note' => $mode === 'specified' && $take < (float) $days ? 'Capped at '.self::days($take).': the most the rule allows now' : null];
            }
        }

        return ['lines' => $lines, 'skipped' => $skipped, 'period' => $period];
    }

    /**
     * "Process": the batch with its reviewed lines; processed at once, or waiting for approval when the setting asks.
     *
     * @param  array<string, mixed>  $who
     */
    public function submit(LeaveType $type, string $mode, ?float $days, ?string $payMonth, array $who, ?string $note, User $actor): LeaveEncashmentBatch
    {
        $this->assertActor($actor);
        $payMonth = PayMonth::parse($payMonth);
        $review = $this->preview($type, $mode, $days, $who, $actor);
        if ($review['lines'] === []) {
            throw new RuntimeException('Nobody can encash '.$type->name.' now: '.count($review['skipped']).' skipped (see the review).');
        }
        $approval = $this->approvalRequired();

        $batch = $this->as($actor, fn () => DB::transaction(function () use ($type, $mode, $days, $payMonth, $who, $note, $actor, $review, $approval) {
            $batch = new LeaveEncashmentBatch([
                'leave_type_id' => $type->id, 'period_year' => $review['period'], 'pay_month' => $payMonth, 'mode' => $mode,
                'specified_days' => $mode === 'specified' ? round((float) $days, 2) : null, 'applicability' => self::cleanWho($who),
                'plan' => [
                    'lines' => array_map(fn (array $l) => array_filter(['employee_id' => $l['employee_id'], 'days' => $l['days'], 'note' => $l['note']], fn ($v) => $v !== null), $review['lines']),
                    'skipped' => array_map(fn (array $s) => ['employee_id' => $s['employee_id'], 'reason' => $s['reason']], $review['skipped']),
                ],
                'employee_count' => count($review['lines']), 'status' => $approval ? 'pending' : 'processing',
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null, 'created_by' => $actor->id,
            ]);
            $batch->withAuditReason($note)->save();
            $this->audit->record(AuditAction::Submitted, 'leave', $batch, reason: $note, actor: $actor, metadata: [
                'leave_type' => $type->code, 'mode' => $mode, 'days' => $mode === 'specified' ? (float) $days : null, 'pay_month' => $payMonth,
                'employees' => count($review['lines']), 'skipped' => count($review['skipped']), 'approval' => $approval,
            ]);
            if (! $approval) {
                ProcessLeaveEncashmentBatch::dispatch((int) $batch->id)->afterCommit();
            }

            return $batch;
        }));

        return $batch->refresh();
    }

    /** Why this person cannot approve this batch (null: they can). */
    public function approvalRefusal(LeaveEncashmentBatch $batch, User $user): ?string
    {
        if (! $this->mayManage($user)) {
            return 'Approving an encashment batch needs the right to manage leave.';
        }
        if ($batch->status !== 'pending') {
            return 'This batch is no longer waiting for approval.';
        }
        if ((int) $batch->created_by === (int) $user->id) {
            return 'You made this batch: someone else approves it.';
        }
        $ids = $batch->plannedEmployeeIds();
        if (Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $ids)->where('user_id', $user->id)->exists()) {
            return 'You are in this batch: someone else approves it.';
        }
        $reachable = $ids === [] ? 0 : $this->scopes->employeeKeys($user, $this->scopes->moduleFor(LeaveEncashment::class))->whereIn('employees.id', $ids)->count();
        if ($reachable < count($ids)) {
            return (count($ids) - $reachable).' employee(s) of this batch are outside your access: someone who can reach all of them approves it.';
        }

        return null;
    }

    /** The second person's approval: the batch is processed in their name. */
    public function approve(LeaveEncashmentBatch $batch, User $approver, ?string $note = null): LeaveEncashmentBatch
    {
        return $this->as($approver, fn () => DB::transaction(function () use ($batch, $approver, $note) {
            $locked = LeaveEncashmentBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if (($refusal = $this->approvalRefusal($locked, $approver)) !== null) {
                throw new RuntimeException($refusal);
            }
            // Decided by a delegate (out of office) in the approver's name: the delegate is held to the same separation.
            $delegateId = (int) (DelegatedDecision::current()['delegate_id'] ?? 0);
            if ($delegateId !== 0 && ($delegateId === (int) $locked->created_by
                || Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $locked->plannedEmployeeIds())->where('user_id', $delegateId)->exists())) {
                throw new RuntimeException('You made this batch or are in it: someone else approves it.');
            }
            $note = filled($note) ? mb_substr(trim((string) $note), 0, 500) : null;
            $locked->withAuditReason($note)->update(['status' => 'processing', 'approved_by' => $approver->id, 'approved_at' => now(), 'decision_note' => $note]);
            $this->audit->record(AuditAction::Approved, 'leave', $locked, reason: $note, actor: $approver, metadata: ['employees' => (int) $locked->employee_count]);
            ProcessLeaveEncashmentBatch::dispatch((int) $locked->id)->afterCommit();

            return $locked;
        }))->refresh();
    }

    public function reject(LeaveEncashmentBatch $batch, User $approver, string $note): LeaveEncashmentBatch
    {
        if (trim($note) === '') {
            throw new RuntimeException('Give a reason for rejecting.');
        }

        return $this->as($approver, fn () => DB::transaction(function () use ($batch, $approver, $note) {
            $locked = LeaveEncashmentBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if (! $this->mayManage($approver)) {
                throw new AuthorizationException('Deciding an encashment batch needs the right to manage leave.');
            }
            if ($locked->status !== 'pending') {
                throw new RuntimeException('This batch is no longer waiting for approval.');
            }
            if ((int) $locked->created_by === (int) $approver->id) {
                throw new RuntimeException('You made this batch: withdraw it instead.');
            }
            $locked->withAuditReason($note)->update(['status' => 'rejected', 'approved_by' => $approver->id, 'approved_at' => now(), 'decision_note' => mb_substr(trim($note), 0, 500)]);
            $this->audit->record(AuditAction::Rejected, 'leave', $locked, reason: $note, actor: $approver);

            return $locked;
        }))->refresh();
    }

    /** Who made it (or anyone who manages leave) takes back a batch still waiting for approval. */
    public function withdraw(LeaveEncashmentBatch $batch, User $actor, string $reason): LeaveEncashmentBatch
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for withdrawing.');
        }
        $this->assertActor($actor);

        return $this->as($actor, fn () => DB::transaction(function () use ($batch, $actor, $reason) {
            $locked = LeaveEncashmentBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new RuntimeException('Only a batch waiting for approval can be withdrawn.');
            }
            $locked->withAuditReason($reason)->update(['status' => 'withdrawn', 'decision_note' => mb_substr(trim($reason), 0, 500)]);
            $this->audit->record(AuditAction::Cancelled, 'leave', $locked, reason: $reason, actor: $actor, metadata: ['was' => 'pending']);

            return $locked;
        }))->refresh();
    }

    /**
     * Create the batch's encashments, already approved (queued after approval, or at once without it). Safe to run again:
     * an employee who already has their encashment from this batch is not encashed twice.
     */
    public function process(LeaveEncashmentBatch $batch): LeaveEncashmentBatch
    {
        $batch = LeaveEncashmentBatch::query()->with('leaveType')->find($batch->getKey());
        if ($batch === null || $batch->status !== 'processing') {
            return $batch ?? throw new RuntimeException('The batch is gone.');
        }
        $actor = User::query()->find($batch->approved_by ?? $batch->created_by) ?? throw new RuntimeException('Nobody to process the batch for.');
        $type = $batch->leaveType ?? throw new RuntimeException('The leave type of the batch is gone.');

        return $this->as($actor, function () use ($batch, $actor, $type) {
            $results = (array) ($batch->results ?? []);
            $this->audit->operation('leave', 'Encashment batch #'.$batch->id.' processed', function () use ($batch, $actor, $type, &$results) {
                $done = 0;
                $failed = 0;
                foreach ((array) ($batch->plan['lines'] ?? []) as $line) {
                    $employeeId = (int) $line['employee_id'];
                    try {
                        $results[$employeeId] = DB::transaction(fn () => $this->encashLine($batch, $type, $employeeId, (float) $line['days'], $actor));
                    } catch (RuntimeException $e) {
                        $results[$employeeId] = ['status' => 'skipped', 'reason' => $e->getMessage()];
                    }
                    $results[$employeeId]['status'] === 'encashed' ? $done++ : $failed++;
                }

                return ['succeeded' => $done, 'failed' => $failed];
            }, $batch->note, LeaveEncashment::class);

            $encashed = array_filter($results, fn (array $r) => $r['status'] === 'encashed');
            $batch->update([
                'status' => 'processed', 'processed_at' => now(), 'results' => $results,
                'encashed_count' => count($encashed), 'encashed_days' => round(array_sum(array_map(fn (array $r) => (float) ($r['days'] ?? 0), $encashed)), 2),
            ]);

            return $batch->refresh();
        });
    }

    /**
     * "Unprocess" (all employees, or the ones chosen): cancel the batch's encashments not paid yet, in the person's access
     * scope. Paid ones stay, and so do ones a pay run being prepared already takes.
     *
     * @param  list<int>|null  $employeeIds  null = all employees
     * @return array{cancelled: int, kept: list<array{employee_id: int, label: string, reason: string}>, out_of_scope: int}
     */
    public function unprocess(LeaveEncashmentBatch $batch, User $actor, ?array $employeeIds, string $reason): array
    {
        $this->assertActor($actor);
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for unprocessing.');
        }
        if ($batch->status !== 'processed') {
            throw new RuntimeException('Only a processed batch can be unprocessed.');
        }
        if ($employeeIds !== null && $employeeIds === []) {
            throw new RuntimeException('Choose at least one employee.');
        }

        return $this->as($actor, function () use ($batch, $actor, $employeeIds, $reason) {
            $out = ['cancelled' => 0, 'kept' => [], 'out_of_scope' => 0];
            $chosen = fn (Builder $q) => $q->where('leave_encashment_batch_id', $batch->id)->whereIn('status', ['approved', 'paid'])
                ->when($employeeIds !== null, fn (Builder $w) => $w->whereIn('employee_id', array_map('intval', $employeeIds)));
            $visible = $chosen(LeaveEncashment::query())->with('employee.person')->orderBy('id')->get();
            $out['out_of_scope'] = max(0, $chosen(LeaveEncashment::query()->withoutGlobalScope(AccessScope::class))->count() - $visible->count());

            $this->audit->operation('leave', 'Encashment batch #'.$batch->id.' unprocessed', function () use ($visible, $batch, $reason, &$out) {
                foreach ($visible as $encashment) {
                    $label = (string) $encashment->employee?->auditLabel();
                    if ($encashment->status === 'paid') {
                        $out['kept'][] = ['employee_id' => (int) $encashment->employee_id, 'label' => $label, 'reason' => 'Already paid: it stays.'];

                        continue;
                    }
                    if (($where = $this->payments->for((int) $encashment->employee_id)[(int) $encashment->id] ?? null) !== null) {
                        $out['kept'][] = ['employee_id' => (int) $encashment->employee_id, 'label' => $label, 'reason' => 'Being paid by '.$where.': it stays.'];

                        continue;
                    }
                    try {
                        $this->adjustments->cancelEncashment($encashment, 'Encashment batch #'.$batch->id.' unprocessed: '.trim($reason));
                        $out['cancelled']++;
                    } catch (RuntimeException $e) {
                        $out['kept'][] = ['employee_id' => (int) $encashment->employee_id, 'label' => $label, 'reason' => $e->getMessage()];
                    }
                }

                return ['succeeded' => $out['cancelled'], 'failed' => count($out['kept'])];
            }, $reason, LeaveEncashment::class);

            if ($out['cancelled'] > 0) {
                $left = LeaveEncashment::query()->withoutGlobalScope(AccessScope::class)->where('leave_encashment_batch_id', $batch->id)->whereIn('status', ['approved', 'paid'])->exists();
                $batch->withAuditReason($reason)->update(['status' => $left ? 'processed' : 'unprocessed', 'unprocessed_by' => $actor->id, 'unprocessed_at' => now(), 'unprocess_reason' => mb_substr(trim($reason), 0, 500)]);
            }

            return $out;
        });
    }

    /**
     * The most this employee may encash of the type now, and why it is nothing when it is.
     *
     * @return array{days: float, reason: string}
     */
    public function most(Employee $employee, LeaveType $type, int $period): array
    {
        $a = $this->adjustments->encashmentAllowance($employee, $type, $period);
        if (! $a['allowed']) {
            return ['days' => 0.0, 'reason' => (string) $a['reason']];
        }
        // Days of encashment requests still waiting are kept back from the balance (the year limit already counts them).
        $waiting = (float) LeaveEncashment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)->where('period_year', $period)->where('status', 'pending')->sum('days');
        $room = floor(($a['available'] - $a['keep'] - $waiting) * 2) / 2;
        $days = max(0.0, min($a['max_now'], $room));
        $reason = match (true) {
            $days > 0 => '',
            $a['available'] <= 0 => 'No '.$type->name.' balance to encash.',
            $a['keep'] > 0 && $a['available'] - $a['keep'] <= 0 => 'The rule keeps a balance of '.self::days($a['keep']).' and only '.self::days($a['available']).' is available.',
            $waiting > 0 && $a['available'] - $a['keep'] - $waiting <= 0 => self::days($waiting).' already asked for in encashment requests waiting for a decision.',
            $a['year_limit'] !== null && $a['year_limit'] - $a['already'] <= 0 => 'Already encashed or asked for the '.self::days($a['year_limit']).' a year the rule allows.',
            default => 'Nothing to encash now under the rule.',
        };

        return ['days' => round($days, 2), 'reason' => $reason];
    }

    /** "3 days", "0.5 day", "1 day". */
    public static function days(float $days): string
    {
        return rtrim(rtrim(number_format($days, 2), '0'), '.').' '.($days == 1.0 || $days == 0.5 ? 'day' : 'days');
    }

    /** @return array{status: string, days?: float, encashment_id?: int, reason?: string} */
    private function encashLine(LeaveEncashmentBatch $batch, LeaveType $type, int $employeeId, float $planned, User $actor): array
    {
        $employee = Employee::query()->with('person')->find($employeeId) ?? throw new RuntimeException('Outside the access of the person processing the batch.');
        $this->balances->lockEmployee($employee);
        $existing = LeaveEncashment::query()->withoutGlobalScope(AccessScope::class)->where('leave_encashment_batch_id', $batch->id)->where('employee_id', $employeeId)->first();
        if ($existing !== null) {
            return ['status' => 'encashed', 'days' => (float) $existing->days, 'encashment_id' => (int) $existing->id];
        }
        if (($refusal = $this->refusal($employee, $actor)) !== null) {
            throw new RuntimeException($refusal);
        }
        $most = $this->most($employee, $type, (int) $batch->period_year);
        $days = min($planned, $most['days']);
        if ($days <= 0) {
            throw new RuntimeException($most['reason'] !== '' ? $most['reason'] : 'Nothing to encash now under the rule.');
        }

        $note = 'Encashment batch #'.$batch->id;
        $encashment = new LeaveEncashment([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'period_year' => (int) $batch->period_year, 'days' => $days, 'status' => 'approved',
            'reason' => $batch->note, 'requested_by' => $batch->created_by, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note,
            'leave_encashment_batch_id' => $batch->id, 'pay_month' => $batch->pay_month,
        ]);
        $encashment->withAuditReason($note)->save();
        $this->balances->post($employee, $type, (int) $batch->period_year, 'encashment', -$days, $encashment, $note);
        $this->audit->record(AuditAction::Approved, 'leave', $encashment, reason: $note, actor: $actor, metadata: ['leave_encashment_batch_id' => (int) $batch->id]);
        LeaveEvent::dispatch('leave.encashment_approved', $employee, $encashment, ['leave_type' => $type->code, 'days' => $days, 'batch' => (int) $batch->id]);

        return ['status' => 'encashed', 'days' => $days, 'encashment_id' => (int) $encashment->id];
    }

    /** Why an employee is left out whatever the rule says (null: not left out). */
    private function refusal(Employee $employee, User $actor): ?string
    {
        if ($employee->user_id !== null && (int) $employee->user_id === (int) $actor->id) {
            return 'Your own encashment: ask for it in My encashments.';
        }
        if (! $employee->lifecycle_state?->isEmployed()) {
            return 'Not employed now.';
        }

        return null;
    }

    private function assertTerms(LeaveType $type, string $mode, ?float $days): void
    {
        if ($type->status !== ActiveStatus::Active || ! $type->is_encashable) {
            throw new RuntimeException("{$type->name} cannot be encashed.");
        }
        if (! array_key_exists($mode, LeaveEncashmentBatch::MODES)) {
            throw new RuntimeException('Choose "as per policy" or a number of days.');
        }
        if ($mode === 'specified' && ($days === null || $days <= 0 || fmod($days * 2, 1.0) !== 0.0 || $days > 365)) {
            throw new RuntimeException('Give the days to encash in half days, more than 0.');
        }
    }

    private function assertActor(User $actor): void
    {
        if (! $this->mayManage($actor)) {
            throw new AuthorizationException('Encashing leave for employees needs the right to manage leave.');
        }
    }

    /**
     * @param  array<string, mixed>  $who
     * @return array<string, mixed> what was chosen, as kept on the batch (empty values dropped)
     */
    private static function cleanWho(array $who): array
    {
        $keep = ['source', 'employee_ids', 'codes', 'everyone', 'lifecycle_state', 'joined_from', 'joined_until', ...EmployeeSelection::ORGANISATION];

        return array_filter(array_intersect_key($who, array_flip($keep)), fn ($v) => $v !== null && $v !== '' && $v !== [] && $v !== false);
    }

    /** Run as $user, so the access scope, ledger rows and audit trail name the deciding person. */
    private function as(User $user, Closure $callback): mixed
    {
        if ((int) auth()->id() === (int) $user->id) {
            return $callback();
        }
        $previous = auth()->user();
        auth()->setUser($user);
        try {
            return $callback();
        } finally {
            $previous ? auth()->setUser($previous) : auth()->forgetUser();
        }
    }
}
