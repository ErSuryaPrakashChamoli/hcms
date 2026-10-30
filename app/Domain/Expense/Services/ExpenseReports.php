<?php

namespace App\Domain\Expense\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Events\ExpenseEvent;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Expense\Models\ExpenseReportTravel;
use App\Domain\Expense\Policies\ExpensePolicy;
use App\Domain\Expense\Support\ExpenseCommitments;
use App\Domain\Expense\Support\ExpenseSettings;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Support\Numbering\NumberSeries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

/**
 * HROne setup parity EX-C: expense reports, from draft to decision (and the recorded payment, EX-D).
 *
 * - Draft: the employee (or an approver in scope, on their behalf) adds unreported, unexpired expenses; with
 *   expense.report.multiple_months off they must share a month, with expense.report.mixed_workflow_groups off a workflow
 *   group (exceptional expenses always go apart).
 * - Submit: inside a submission window (expense.report_window.*; filing on behalf is exempt). The report is split into
 *   parts by workflow group (ExpenseSettings::workflowGroup) and each part raises expense.report_submitted — or
 *   expense.exceptional_submitted for the exceptional part — with its workflow group in the context, so a workflow (and
 *   a condition node in it) decides it; parts without a workflow wait in the Approval Center.
 * - Decide a part (in scope, never one's own): approve line by line (each line up to its claimable amount; 0 rejects
 *   the line), reject, or return the whole report to the employee (every part is decided again after resubmission).
 *   Once every part is decided the report is approved, partly approved or rejected; dispensed advances are settled
 *   against what it repays, oldest first (ExpenseAdvances), and the rest is its net payable.
 * - Cancel (draft, returned, or submitted with no part decided). Mark paid (Pay expenses, `expense.pay`): an outside
 *   payment's reference and date.
 * - EX-E payment: with expense.payment.step_required off a report with a net payable is scheduled for payroll when it is
 *   decided; with it on (the default) it waits on Pay expenses, which pays it outside payroll or schedules it for payroll.
 *   The payroll run that carries a scheduled report pays it when that run is finalized (`paidThrough`), and at exit the
 *   final settlement pays what is still unpaid. While a run not yet finalized or an unpaid final settlement carries a
 *   report (ExpenseCommitments), it is not paid any other way.
 * - EX-F trips: a report covers the trips of its expenses (expense_report_travel, kept in step as expenses come and go);
 *   with expense.report.multiple_travel off its expenses belong to one trip.
 */
final class ExpenseReports
{
    public function __construct(
        private readonly ExpenseReceipts $receipts,
        private readonly ExpenseAdvances $advances,
        private readonly ExpenseSettings $settings,
        private readonly CurrencyRates $rates,
        private readonly NumberSeries $numbers,
        private readonly AuditRecorder $audit,
        private readonly WorkflowEngine $workflows,
        private readonly ExpenseCommitments $commitments,
    ) {}

    /** @param  list<int>  $receiptIds */
    public function create(Employee $employee, string $title, array $receiptIds = [], ?User $actor = null): ExpenseReport
    {
        $actor = $this->authoriseFor($actor, $employee);
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 160) {
            throw new ExpenseRuleViolation('Give the report a title (at most 160 characters).');
        }
        $this->numbers->prepare('expense_report', NumberSeries::companyOf($employee));

        return DB::transaction(function () use ($employee, $title, $receiptIds, $actor) {
            $report = new ExpenseReport(['number' => $this->numbers->next('expense_report', NumberSeries::companyOf($employee)), 'employee_id' => $employee->id,
                'title' => $title, 'status' => 'draft', 'currency' => $this->rates->baseCurrency(), 'created_by' => $actor->id]);
            $report->save();
            if ($receiptIds !== []) {
                $this->attach($report, $receiptIds);
            }

            return $report->refresh();
        });
    }

    /** @param  list<int>  $receiptIds */
    public function addReceipts(ExpenseReport $report, array $receiptIds, ?User $actor = null): ExpenseReport
    {
        $this->authoriseChange($actor, $report);

        return DB::transaction(function () use ($report, $receiptIds) {
            $locked = $this->lockReport($report);
            $this->assertEditable($locked);
            $this->attach($locked, $receiptIds);

            return $locked->refresh();
        });
    }

    public function removeReceipt(ExpenseReport $report, ExpenseReceipt $receipt, ?User $actor = null): ExpenseReport
    {
        $this->authoriseChange($actor, $report);

        return DB::transaction(function () use ($report, $receipt) {
            $locked = $this->lockReport($report);
            $this->assertEditable($locked);
            $line = ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->whereKey($receipt->getKey())->where('expense_report_id', $locked->id)->lockForUpdate()->first()
                ?? throw new ExpenseRuleViolation('That expense is not in this report.');
            $line->withAuditReason('Removed from report '.$locked->number)->update(['expense_report_id' => null, 'expense_report_part_id' => null, 'workflow_group' => null, 'status' => 'unreported', 'approved_amount' => null, 'approver_note' => null]);
            $this->totals($locked);
            $this->syncTrips($locked);

            return $locked->refresh();
        });
    }

    public function submit(ExpenseReport $report, ?User $actor = null): ExpenseReport
    {
        $employee = $this->employee($report);
        $actor = $this->authoriseFor($actor, $employee);
        $onBehalf = (int) $employee->user_id !== (int) $actor->id;
        // HROne setup parity P4: the employee request lock refuses the employee's own report while it applies.
        app(SelfServiceLock::class)->assertOpen($employee, 'expense', $actor, ExpenseRuleViolation::class);

        $submitted = DB::transaction(function () use ($report, $employee, $actor, $onBehalf) {
            $locked = $this->lockReport($report);
            $this->assertEditable($locked);
            $today = CarbonImmutable::today();
            $cycle = $this->settings->windowCycle($today);
            if ($cycle === null && ! $onBehalf) {
                throw new ExpenseRuleViolation('Expense reports can be submitted between '.$this->settings->describeWindows($today).'.');
            }
            $lines = $this->lines($locked);
            if ($lines->isEmpty()) {
                throw new ExpenseRuleViolation('Add at least one expense to the report.');
            }
            foreach ($lines as $line) {
                if ($this->receipts->hasExpired($line, $today) && $locked->status === 'draft') {
                    throw new ExpenseRuleViolation("{$line->number} has expired: remove it from the report.");
                }
            }
            $this->assertCombination($lines);
            $this->syncTrips($locked);

            $groups = $lines->groupBy(fn (ExpenseReceipt $r) => $this->settings->workflowGroup($r->category, (bool) $r->is_exceptional));
            $parts = [];
            foreach ($groups as $group => $receipts) {
                $part = ExpenseReportPart::query()->create(['expense_report_id' => $locked->id, 'employee_id' => $locked->employee_id, 'workflow_group' => $group,
                    'kind' => $group === ExpenseSettings::EXCEPTIONAL ? 'exceptional' : 'standard', 'label' => $this->groupLabel($group, $receipts->first()->category),
                    'status' => 'pending', 'total_claimed' => round((float) $receipts->sum(fn (ExpenseReceipt $r) => (float) $r->claimable_amount), 2)]);
                foreach ($receipts as $receipt) {
                    $receipt->withAuditReason('Submitted in report '.$locked->number)->update(['expense_report_part_id' => $part->id, 'workflow_group' => $group, 'status' => 'reported', 'approved_amount' => null, 'approver_note' => null]);
                }
                $parts[] = $part;
            }
            $locked->withAuditReason($onBehalf ? 'Submitted on behalf of the employee' : null)->update([
                'status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id, 'filed_on_behalf' => $onBehalf, 'window_cycle' => $cycle ?: null,
                'period_from' => $lines->min(fn (ExpenseReceipt $r) => $r->expense_date->toDateString()), 'period_to' => $lines->max(fn (ExpenseReceipt $r) => $r->expense_date->toDateString()),
                'total_approved' => 0, 'reimbursable_approved' => 0, 'advance_adjusted' => 0, 'net_payable' => 0, 'decided_at' => null, 'decided_by' => null,
            ]);
            $this->totals($locked);
            $this->audit->record(AuditAction::Submitted, 'expense', $locked, reason: $onBehalf ? 'Submitted on behalf of the employee' : null, actor: $actor, metadata: ['parts' => count($parts), 'claimed' => (float) $locked->refresh()->total_claimed]);

            foreach ($parts as $part) {
                ExpenseEvent::dispatch($part->isExceptional() ? 'expense.exceptional_submitted' : 'expense.report_submitted', $employee, $part, [
                    'number' => $locked->number, 'report_id' => $locked->id, 'workflow_group' => $part->workflow_group, 'part' => $part->label,
                    'amount' => (float) $part->total_claimed, 'currency' => $locked->currency, 'receipts' => $groups[$part->workflow_group]->count(),
                ], $this->managerUsers($employee));
            }

            return $locked;
        });

        return $submitted->refresh();
    }

    /**
     * Approves a part line by line: $lines maps a receipt id to the amount approved (or [amount, note]); a line left out
     * is approved as claimed, 0 rejects it. Every line set to 0 is a rejection and needs the reason.
     *
     * @param  array<int, float|int|string|array{amount?: float|int|string|null, note?: ?string}>|null  $lines
     */
    public function approvePart(ExpenseReportPart $part, ?array $lines = null, ?string $note = null, ?User $actor = null): ExpenseReportPart
    {
        return DB::transaction(function () use ($part, $lines, $note, $actor) {
            [$report, $locked, $actor] = $this->decidable($part, $actor);
            $total = 0.0;
            $full = true;
            $decided = [];
            foreach ($this->partLines($locked) as $receipt) {
                $claimable = (float) $receipt->claimable_amount;
                $line = $lines[$receipt->id] ?? null;
                $value = is_array($line) ? ($line['amount'] ?? null) : $line;
                $amount = $value === null || $value === '' ? $claimable : (is_numeric($value) ? round((float) $value, 2) : -1);
                if ($amount < 0 || $amount > $claimable + 0.004) {
                    throw new ExpenseRuleViolation("{$receipt->number}: approve between 0 and the claimable ".number_format($claimable, 2).'.');
                }
                $lineNote = is_array($line) && filled($line['note'] ?? null) ? mb_substr(trim((string) $line['note']), 0, 500) : null;
                $decided[] = [$receipt, $amount, $lineNote];
                $total += $amount;
                $full = $full && abs($amount - $claimable) < 0.005;
            }
            if ($total <= 0 && blank($note)) {
                throw new ExpenseRuleViolation('Every line is set to 0: give the reason, as for a rejection.');
            }
            foreach ($decided as [$receipt, $amount, $lineNote]) {
                $receipt->withAuditReason($lineNote ?? $note)->update(['approved_amount' => $amount, 'approver_note' => $lineNote,
                    'status' => $amount <= 0 ? 'rejected' : (abs($amount - (float) $receipt->claimable_amount) < 0.005 ? 'approved' : 'partly_approved')]);
            }
            $status = $total <= 0 ? 'rejected' : ($full ? 'approved' : 'partly_approved');
            $locked->withAuditReason($note)->update(['status' => $status, 'total_approved' => round($total, 2), 'decided_at' => now(), 'decided_by' => $actor->id, 'decision_note' => self::short($note)]);
            $this->audit->record($status === 'rejected' ? AuditAction::Rejected : AuditAction::Approved, 'expense', $locked, reason: $note, actor: $actor, metadata: ['report' => $report->number, 'approved' => round($total, 2)]);
            $this->settle($report, $actor);

            return $locked->refresh();
        });
    }

    public function rejectPart(ExpenseReportPart $part, string $note, ?User $actor = null): ExpenseReportPart
    {
        if (trim($note) === '') {
            throw new ExpenseRuleViolation('Give the reason for rejecting.');
        }

        return DB::transaction(function () use ($part, $note, $actor) {
            [$report, $locked, $actor] = $this->decidable($part, $actor);
            foreach ($this->partLines($locked) as $receipt) {
                $receipt->withAuditReason($note)->update(['approved_amount' => 0, 'status' => 'rejected']);
            }
            $locked->withAuditReason($note)->update(['status' => 'rejected', 'total_approved' => 0, 'decided_at' => now(), 'decided_by' => $actor->id, 'decision_note' => self::short($note)]);
            $this->audit->record(AuditAction::Rejected, 'expense', $locked, reason: $note, actor: $actor, metadata: ['report' => $report->number]);
            $this->settle($report, $actor);

            return $locked->refresh();
        });
    }

    /** Sends a submitted report back to the employee with a note; every part is decided again once it is resubmitted. */
    public function returnReport(ExpenseReport|ExpenseReportPart $target, string $note, ?User $actor = null): ExpenseReport
    {
        if (trim($note) === '') {
            throw new ExpenseRuleViolation('Tell the employee what to change.');
        }
        $actor ??= auth()->user();

        return DB::transaction(function () use ($target, $note, $actor) {
            $report = $this->lockReport($target instanceof ExpenseReportPart ? $target->report()->withoutGlobalScope(AccessScope::class)->firstOrFail() : $target);
            if ($report->status !== 'submitted') {
                throw new ExpenseRuleViolation('Only a submitted report waiting for approval can be returned.');
            }
            $pending = ExpenseReportPart::query()->withoutGlobalScope(AccessScope::class)->where('expense_report_id', $report->id)->where('status', 'pending')
                ->when($target instanceof ExpenseReportPart, fn ($q) => $q->whereKey($target->getKey()))->get();
            if ($actor === null || $pending->isEmpty() || ! $pending->contains(fn (ExpenseReportPart $p) => app(ExpensePolicy::class)->approve($actor, $p))) {
                throw new ExpenseRuleViolation('You cannot return this report.');
            }
            $this->closeParts($report, 'returned', 'Report returned to the employee');
            foreach ($this->lines($report) as $receipt) {
                $receipt->withAuditReason($note)->update(['expense_report_part_id' => null, 'approved_amount' => null, 'approver_note' => null, 'status' => 'reported']);
            }
            $report->withAuditReason($note)->update(['status' => 'returned', 'returned_at' => now(), 'return_note' => mb_substr($note, 0, 500), 'total_approved' => 0, 'reimbursable_approved' => 0]);
            $this->audit->record(AuditAction::StatusChange, 'expense', $report, [['field' => 'status', 'before' => 'submitted', 'after' => 'returned', 'sensitive' => false]], $note, actor: $actor);
            $this->tell('expense.report_returned', $report, ['note' => $note]);

            return $report->refresh();
        });
    }

    public function cancel(ExpenseReport $report, string $reason, ?User $actor = null): ExpenseReport
    {
        if (trim($reason) === '') {
            throw new ExpenseRuleViolation('Give the reason for cancelling the report.');
        }
        $this->authoriseChange($actor, $report);

        return DB::transaction(function () use ($report, $reason, $actor) {
            $locked = $this->lockReport($report);
            if (! in_array($locked->status, ['draft', 'returned', 'submitted'], true)) {
                throw new ExpenseRuleViolation('A decided report cannot be cancelled.');
            }
            if ($locked->status === 'submitted' && ExpenseReportPart::query()->withoutGlobalScope(AccessScope::class)->where('expense_report_id', $locked->id)->where('status', '!=', 'pending')->whereNotIn('status', ['returned', 'cancelled'])->exists()) {
                throw new ExpenseRuleViolation('Part of the report is already decided: it can no longer be cancelled.');
            }
            $this->closeParts($locked, 'cancelled', 'Report cancelled');
            foreach ($this->lines($locked) as $receipt) {
                $receipt->withAuditReason($reason)->update(['expense_report_id' => null, 'expense_report_part_id' => null, 'workflow_group' => null, 'approved_amount' => null, 'approver_note' => null, 'status' => 'unreported']);
            }
            $this->syncTrips($locked);
            $before = $locked->status;
            $locked->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 500), 'total_claimed' => 0]);
            $this->audit->record(AuditAction::Cancelled, 'expense', $locked, reason: $reason, actor: $actor ?? auth()->user());
            if ($before === 'submitted') {
                ExpenseEvent::dispatch('expense.report_cancelled', $this->employee($locked), $locked, ['number' => $locked->number, 'reason' => $reason], $this->managerUsers($this->employee($locked)));
            }

            return $locked->refresh();
        });
    }

    /** Pay expenses: an approved report's net payable was paid outside payroll; its reference and date are recorded. */
    public function markPaid(ExpenseReport $report, string $reference, CarbonInterface|string $on, ?User $actor = null): ExpenseReport
    {
        $actor ??= auth()->user();
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 120) {
            throw new ExpenseRuleViolation('Give the payment reference (at most 120 characters).');
        }
        $on = CarbonImmutable::parse($on)->startOfDay();
        if ($on->gt(CarbonImmutable::today())) {
            throw new ExpenseRuleViolation('A payment is recorded on or before today.');
        }

        return DB::transaction(function () use ($report, $reference, $on, $actor) {
            $locked = $this->lockReport($report);
            if ($actor === null || ! app(ExpensePolicy::class)->pay($actor, $locked)) {
                throw new ExpenseRuleViolation('You cannot record payments for this report.');
            }
            if (! $locked->isPayable()) {
                throw new ExpenseRuleViolation('Only an approved report with an amount to pay, not yet paid, can be marked paid.');
            }
            if ($locked->decided_at !== null && $on->lt($locked->decided_at->copy()->startOfDay())) {
                throw new ExpenseRuleViolation('A report is paid on or after its approval.');
            }
            // EX-E: a report a payroll run or a final settlement already carries is paid by it, never twice.
            if (($where = $this->commitments->of(PayrollCommitments::REPORT, (int) $locked->employee_id, (int) $locked->id)) !== null) {
                throw new ExpenseRuleViolation("{$locked->number} is already being paid in {$where}: it cannot be paid outside payroll as well.");
            }
            $locked->withAuditReason('Paid outside payroll: '.$reference)->update(['status' => 'paid', 'payment_channel' => 'bank', 'payment_scheduled_at' => null, 'payment_scheduled_by' => null,
                'paid_on' => $on->toDateString(), 'payment_reference' => $reference, 'paid_by' => $actor->id]);
            $this->tell('expense.report_paid', $locked, ['amount' => (float) $locked->net_payable, 'reference' => $reference, 'paid_on' => $on->toDateString()]);

            return $locked->refresh();
        });
    }

    /**
     * EX-E (Pay expenses → Pay with payroll): an approved report waiting for payment is scheduled for payroll; the run whose
     * period covers today pays it when it is finalized. Pay expenses may still pay it outside payroll until a run carries it.
     */
    public function scheduleForPayroll(ExpenseReport $report, ?User $actor = null): ExpenseReport
    {
        $actor ??= auth()->user();

        return DB::transaction(function () use ($report, $actor) {
            $locked = $this->lockReport($report);
            if ($actor === null || ! app(ExpensePolicy::class)->pay($actor, $locked)) {
                throw new ExpenseRuleViolation('You cannot record payments for this report.');
            }
            if (! $locked->isPayable()) {
                throw new ExpenseRuleViolation('Only an approved report with an amount to pay, not yet paid, can be paid with payroll.');
            }
            if ($locked->payment_channel === 'payroll') {
                throw new ExpenseRuleViolation("{$locked->number} is already scheduled for payroll.");
            }
            if (($where = $this->commitments->of(PayrollCommitments::REPORT, (int) $locked->employee_id, (int) $locked->id)) !== null) {
                throw new ExpenseRuleViolation("{$locked->number} is already being paid in {$where}.");
            }
            $locked->withAuditReason('Scheduled for payroll')->update(['payment_channel' => 'payroll', 'payment_scheduled_at' => now(), 'payment_scheduled_by' => $actor->id]);
            $this->tell('expense.report_scheduled', $locked, ['amount' => (float) $locked->net_payable, 'channel' => 'payroll']);

            return $locked->refresh();
        });
    }

    /**
     * EX-E: these reports were paid with payroll (the run is finalized) or in the final settlement (it is paid). A report
     * no longer payable is left alone; each one paid tells the employee.
     *
     * @param  list<int>  $ids
     */
    public function paidThrough(array $ids, string $channel, CarbonInterface|string $on, string $reference, ?int $payrollRunId = null, ?int $settlementId = null): void
    {
        $on = CarbonImmutable::parse($on)->toDateString();
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $channel, $on, $reference, $payrollRunId, $settlementId) {
                $locked = ExpenseReport::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isPayable() || ($channel === 'payroll' && $locked->payment_channel !== 'payroll')) {
                    return;
                }
                $locked->withAuditReason(($channel === 'payroll' ? 'Paid with ' : 'Paid in ').$reference)->update(['status' => 'paid', 'payment_channel' => $channel, 'paid_on' => $on,
                    'payment_reference' => mb_substr($reference, 0, 120), 'payroll_run_id' => $payrollRunId, 'final_settlement_id' => $settlementId]);
                $this->tell('expense.report_paid', $locked, ['amount' => (float) $locked->net_payable, 'reference' => $reference, 'paid_on' => $on, 'channel' => $channel]);
            });
        }
    }

    /** EX-E (expense.exit.pending_reports): the employee's reports still waiting for a decision, by number. */
    public function waitingForDecision(Employee $employee): array
    {
        return ExpenseReport::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('status', 'submitted')->orderBy('id')->pluck('number')->all();
    }

    /** The label of a workflow group, as parts and the Approval Center show it. */
    public function groupLabel(string $group, ?ExpenseCategory $category = null): string
    {
        return match (true) {
            $group === ExpenseSettings::EXCEPTIONAL => 'Exceptional expenses',
            str_starts_with($group, 'category:') => $category?->code === substr($group, 9) ? $category->name : (ExpenseCategory::query()->where('code', substr($group, 9))->value('name') ?? substr($group, 9)),
            str_starts_with($group, 'group:') => ExpenseGroup::query()->where('code', substr($group, 6))->value('name') ?? substr($group, 6),
            default => 'All expenses',
        };
    }

    /** Decides the report once every part is: totals, status, advances settled oldest first, net payable. */
    private function settle(ExpenseReport $report, User $actor): void
    {
        if (ExpenseReportPart::query()->withoutGlobalScope(AccessScope::class)->where('expense_report_id', $report->id)->where('status', 'pending')->exists()) {
            return;
        }
        $lines = $this->lines($report);
        $approved = round((float) $lines->sum(fn (ExpenseReceipt $r) => (float) $r->approved_amount), 2);
        $reimbursable = round((float) $lines->filter(fn (ExpenseReceipt $r) => $r->reimbursable)->sum(fn (ExpenseReceipt $r) => (float) $r->approved_amount), 2);
        $status = match (true) {
            $approved <= 0 => 'rejected',
            $lines->every(fn (ExpenseReceipt $r) => $r->status === 'approved') => 'approved',
            default => 'partly_approved',
        };
        $adjusted = $status === 'rejected' ? 0.0 : $this->advances->adjustForReport($report, $reimbursable, $actor);
        $net = round($reimbursable - $adjusted, 2);
        // EX-E: without a payment step an approved report goes straight to the next payroll.
        $toPayroll = $status !== 'rejected' && $net > 0 && ! (bool) $this->settings->get('expense.payment.step_required', true);
        $report->withAuditReason('Every part decided')->update(['status' => $status, 'total_approved' => $approved, 'reimbursable_approved' => $reimbursable,
            'advance_adjusted' => $adjusted, 'net_payable' => $net, 'decided_at' => now(), 'decided_by' => $actor->id]
            + ($toPayroll ? ['payment_channel' => 'payroll', 'payment_scheduled_at' => now(), 'payment_scheduled_by' => null] : []));
        $this->tell('expense.report_'.$status, $report, ['approved' => $approved, 'advance_adjusted' => $adjusted, 'net_payable' => round($reimbursable - $adjusted, 2)]);
    }

    /** @param  list<int>  $receiptIds */
    private function attach(ExpenseReport $report, array $receiptIds): void
    {
        $receipts = ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->with('category.group')->whereKey(array_values(array_unique(array_map('intval', $receiptIds))))->lockForUpdate()->get();
        if ($receipts->count() !== count(array_unique(array_map('intval', $receiptIds)))) {
            throw new ExpenseRuleViolation('Choose expenses that exist.');
        }
        foreach ($receipts as $receipt) {
            if ((int) $receipt->employee_id !== (int) $report->employee_id) {
                throw new ExpenseRuleViolation("{$receipt->number} belongs to someone else.");
            }
            if ($receipt->status !== 'unreported') {
                throw new ExpenseRuleViolation("{$receipt->number} is ".strtolower(ExpenseReceipt::STATUSES[$receipt->status] ?? $receipt->status).': only unreported expenses can be added.');
            }
            if ($this->receipts->hasExpired($receipt)) {
                $receipt->withAuditReason('Expense expiry period passed')->update(['status' => 'expired', 'expired_at' => now()]);
                throw new ExpenseRuleViolation("{$receipt->number} has expired and can no longer be reported.");
            }
        }
        $this->assertCombination($this->lines($report)->concat($receipts));
        foreach ($receipts as $receipt) {
            $receipt->withAuditReason('Added to report '.$report->number)->update(['expense_report_id' => $report->id, 'status' => 'reported']);
        }
        $this->totals($report);
        $this->syncTrips($report);
    }

    /** EX-F: the report covers exactly the trips of its expenses. */
    private function syncTrips(ExpenseReport $report): void
    {
        $wanted = ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->where('expense_report_id', $report->id)->whereNotNull('travel_request_id')
            ->distinct()->pluck('travel_request_id')->map(fn ($id) => (int) $id)->all();
        $have = ExpenseReportTravel::query()->where('expense_report_id', $report->id)->get();
        foreach ($have as $row) {
            if (! in_array((int) $row->travel_request_id, $wanted, true)) {
                $row->withAuditReason('No expense of the trip is left in report '.$report->number)->delete();
            }
        }
        foreach (array_diff($wanted, $have->map(fn (ExpenseReportTravel $r) => (int) $r->travel_request_id)->all()) as $tripId) {
            ExpenseReportTravel::query()->create(['expense_report_id' => $report->id, 'travel_request_id' => $tripId]);
        }
    }

    /** Several months / several workflow groups in one report, as the settings allow. */
    private function assertCombination(BaseCollection $lines): void
    {
        if (! (bool) $this->settings->get('expense.report.multiple_travel', false) && $lines->map(fn (ExpenseReceipt $r) => $r->travel_request_id)->filter()->unique()->count() > 1) {
            throw new ExpenseRuleViolation('A report holds the expenses of one trip: put the other trips\' expenses in their own reports.');
        }
        if (! (bool) $this->settings->get('expense.report.multiple_months', true) && $lines->map(fn (ExpenseReceipt $r) => $r->expense_date->format('Y-m'))->unique()->count() > 1) {
            throw new ExpenseRuleViolation('A report holds expenses of one month: put the other months in their own reports.');
        }
        if (! (bool) $this->settings->get('expense.report.mixed_workflow_groups', true)) {
            $groups = $lines->reject(fn (ExpenseReceipt $r) => (bool) $r->is_exceptional)->map(fn (ExpenseReceipt $r) => $this->settings->workflowGroup($r->category, false))->unique();
            if ($groups->count() > 1) {
                throw new ExpenseRuleViolation('A report holds expenses approved the same way: '.$groups->map(fn (string $g) => $this->groupLabel($g))->implode(', ').' go in separate reports.');
            }
        }
    }

    private function totals(ExpenseReport $report): void
    {
        $report->withoutAuditing()->update(['total_claimed' => round((float) $this->lines($report)->sum(fn (ExpenseReceipt $r) => (float) $r->claimable_amount), 2)]);
    }

    /** @return Collection<int, ExpenseReceipt> */
    private function lines(ExpenseReport $report): Collection
    {
        return ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->with('category.group')->where('expense_report_id', $report->id)->orderBy('expense_date')->orderBy('id')->lockForUpdate()->get();
    }

    /** @return Collection<int, ExpenseReceipt> */
    private function partLines(ExpenseReportPart $part): Collection
    {
        return ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->where('expense_report_part_id', $part->id)->orderBy('id')->lockForUpdate()->get();
    }

    /** Parts still open are closed (returned or cancelled) and their running workflows cancelled. */
    private function closeParts(ExpenseReport $report, string $status, string $reason): void
    {
        $parts = ExpenseReportPart::query()->withoutGlobalScope(AccessScope::class)->where('expense_report_id', $report->id)->whereNotIn('status', ['returned', 'cancelled'])->get();
        foreach ($parts as $part) {
            $part->withAuditReason($reason)->update(['status' => $status]);
            WorkflowInstance::query()->where('subject_type', $part->getMorphClass())->where('subject_id', $part->id)
                ->whereIn('status', InstanceStatus::inFlight())->get()
                ->each(fn (WorkflowInstance $instance) => $this->workflows->cancel($instance, $reason));
        }
    }

    /** @return array{0: ExpenseReport, 1: ExpenseReportPart, 2: User} */
    private function decidable(ExpenseReportPart $part, ?User $actor): array
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new ExpenseRuleViolation('A report is decided by a person.');
        }
        $report = $this->lockReport(ExpenseReport::query()->withoutGlobalScope(AccessScope::class)->findOrFail($part->expense_report_id));
        $locked = ExpenseReportPart::query()->withoutGlobalScope(AccessScope::class)->whereKey($part->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'pending' || $report->status !== 'submitted') {
            throw new ExpenseRuleViolation('This part of the report has already been decided.');
        }
        if (! app(ExpensePolicy::class)->approve($actor, $locked)) {
            throw new ExpenseRuleViolation((int) $this->employee($report)->user_id === (int) $actor->id ? 'You cannot decide your own expense report.' : 'You cannot decide this expense report.');
        }

        return [$report, $locked, $actor];
    }

    private function lockReport(ExpenseReport $report): ExpenseReport
    {
        return ExpenseReport::query()->withoutGlobalScope(AccessScope::class)->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertEditable(ExpenseReport $report): void
    {
        if (! $report->isEditable()) {
            throw new ExpenseRuleViolation('This report is '.strtolower(ExpenseReport::STATUSES[$report->status] ?? $report->status).' and can no longer be changed.');
        }
    }

    private function authoriseFor(?User $actor, Employee $employee): User
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new ExpenseRuleViolation('Expense reports are filed by a person.');
        }
        $own = $employee->user_id !== null && (int) $employee->user_id === (int) $actor->id;
        if ($own ? ! $actor->hasPermission('expense.claim') : ! app(ExpensePolicy::class)->onBehalf($actor, $employee)) {
            throw new ExpenseRuleViolation('You cannot file expense reports for this employee.');
        }

        return $actor;
    }

    private function authoriseChange(?User $actor, ExpenseReport $report): void
    {
        $actor ??= auth()->user();
        if ($actor === null || ! app(ExpensePolicy::class)->update($actor, $report)) {
            throw new ExpenseRuleViolation('You cannot change this report.');
        }
    }

    private function employee(ExpenseReport $report): Employee
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($report->employee_id);
    }

    private function tell(string $event, ExpenseReport $report, array $context): void
    {
        $employee = $this->employee($report);
        ExpenseEvent::dispatch($event, $employee, $report, ['number' => $report->number, 'currency' => $report->currency] + $context, array_values(array_filter([$employee->user_id])));
    }

    /** @return list<int> */
    private function managerUsers(Employee $employee): array
    {
        return array_values(array_filter([$employee->currentManager()->with('manager')->first()?->manager?->user_id]));
    }

    /** Notes are kept to their column (500 characters); the audit trail keeps the full text. */
    private static function short(?string $note): ?string
    {
        return $note === null ? null : mb_substr(trim($note), 0, 500);
    }
}
