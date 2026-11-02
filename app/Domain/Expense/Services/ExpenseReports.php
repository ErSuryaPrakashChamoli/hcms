<?php

namespace App\Domain\Expense\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Contracts\PayrollMonths;
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
 * - HROne parity XO-3 (owner decision D-XO7 / EXP-13): with expense.review.suspect_button on, an approver may hold a line
 *   out as suspected (approved at 0 for now, with a note); clearSuspicion() decides it later and the report follows. A
 *   returned report discards every line decision, suspicions included (EXP-1, as built).
 * - HROne parity XO-4 (owner decision D-XO1 / EXP-7): Pay with payroll may name the payroll month (an open, not finalized
 *   month of the employee's company, read through PayrollMonths); blank = the next payroll, as before. A scheduled report
 *   may move to another month or leave payroll while no run carries it. An outside payment may record its mode (bank
 *   transfer, cheque with number and date, cash), the company's paying account and a note. A report is paid once: by
 *   exactly one finalized payroll run of its month, outside payroll, or in the final settlement.
 * - HROne parity XO-5: HR (or a manager) creates a report for an employee in scope (never their own) through create()
 *   with the actor — "Created on behalf of the employee" in the audit trail, `created_by` names who — and may submit it
 *   for them (filed on behalf: exempt from the submission windows, as before).
 */
final class ExpenseReports
{
    /** A line's suspicion, cleared when the line leaves its decision (returned, resubmitted, removed, cancelled). */
    private const NO_SUSPICION = ['suspected_at' => null, 'suspected_by' => null, 'suspicion_note' => null, 'suspicion_cleared_at' => null, 'suspicion_cleared_by' => null];

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
            // HROne parity XO-5: a report HR (or a manager) creates for the employee says so in its audit trail.
            $onBehalf = $employee->user_id === null || (int) $employee->user_id !== (int) $actor->id;
            $report->withAuditReason($onBehalf ? 'Created on behalf of the employee' : null)->save();
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
            $line->withAuditReason('Removed from report '.$locked->number)->update(['expense_report_id' => null, 'expense_report_part_id' => null, 'workflow_group' => null, 'status' => 'unreported', 'approved_amount' => null, 'approver_note' => null] + self::NO_SUSPICION);
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
                    $receipt->withAuditReason('Submitted in report '.$locked->number)->update(['expense_report_part_id' => $part->id, 'workflow_group' => $group, 'status' => 'reported', 'approved_amount' => null, 'approver_note' => null] + self::NO_SUSPICION);
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
     * HROne parity XO-3 (D-XO7): with expense.review.suspect_button on, a line given as [suspected => true, note] is held
     * out as suspected — approved at 0 for now, its note required — and decided later by clearSuspicion(). With the setting
     * off the flag is ignored (as before). A part whose only approved lines are held is partly approved, not rejected.
     *
     * @param  array<int, float|int|string|array{amount?: float|int|string|null, note?: ?string, suspected?: bool}>|null  $lines
     */
    public function approvePart(ExpenseReportPart $part, ?array $lines = null, ?string $note = null, ?User $actor = null): ExpenseReportPart
    {
        return DB::transaction(function () use ($part, $lines, $note, $actor) {
            [$report, $locked, $actor] = $this->decidable($part, $actor);
            $suspicion = $this->suspicionOffered();
            $total = 0.0;
            $full = true;
            $decided = [];
            $held = 0;
            foreach ($this->partLines($locked) as $receipt) {
                $claimable = (float) $receipt->claimable_amount;
                $line = $lines[$receipt->id] ?? null;
                $lineNote = is_array($line) && filled($line['note'] ?? null) ? mb_substr(trim((string) $line['note']), 0, 500) : null;
                if ($suspicion && is_array($line) && filter_var($line['suspected'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    if ($lineNote === null) {
                        throw new ExpenseRuleViolation("{$receipt->number}: say why the expense is suspected.");
                    }
                    $decided[] = [$receipt, 0.0, $lineNote, true];
                    $full = false;
                    $held++;

                    continue;
                }
                $value = is_array($line) ? ($line['amount'] ?? null) : $line;
                $amount = $value === null || $value === '' ? $claimable : (is_numeric($value) ? round((float) $value, 2) : -1);
                if ($amount < 0 || $amount > $claimable + 0.004) {
                    throw new ExpenseRuleViolation("{$receipt->number}: approve between 0 and the claimable ".number_format($claimable, 2).'.');
                }
                $decided[] = [$receipt, $amount, $lineNote, false];
                $total += $amount;
                $full = $full && abs($amount - $claimable) < 0.005;
            }
            if ($total <= 0 && blank($note) && ($held === 0 || $held < count($decided))) {
                throw new ExpenseRuleViolation('Every line is set to 0: give the reason, as for a rejection.');
            }
            foreach ($decided as [$receipt, $amount, $lineNote, $suspected]) {
                if ($suspected) {
                    $receipt->withAuditReason('Held as suspected: '.$lineNote)->update(['approved_amount' => 0, 'approver_note' => $lineNote, 'status' => 'suspected',
                        'suspected_at' => now(), 'suspected_by' => $actor->id, 'suspicion_note' => $lineNote, 'suspicion_cleared_at' => null, 'suspicion_cleared_by' => null]);

                    continue;
                }
                $receipt->withAuditReason($lineNote ?? $note)->update(['approved_amount' => $amount, 'approver_note' => $lineNote,
                    'status' => $amount <= 0 ? 'rejected' : (abs($amount - (float) $receipt->claimable_amount) < 0.005 ? 'approved' : 'partly_approved')]);
            }
            $status = $total <= 0 ? ($held > 0 ? 'partly_approved' : 'rejected') : ($full ? 'approved' : 'partly_approved');
            $locked->withAuditReason($note)->update(['status' => $status, 'total_approved' => round($total, 2), 'decided_at' => now(), 'decided_by' => $actor->id, 'decision_note' => self::short($note)]);
            $this->audit->record($status === 'rejected' ? AuditAction::Rejected : AuditAction::Approved, 'expense', $locked, reason: $note, actor: $actor,
                metadata: ['report' => $report->number, 'approved' => round($total, 2)] + ($held > 0 ? ['held_as_suspected' => $held] : []));
            $this->settle($report, $actor);

            return $locked->refresh();
        });
    }

    /** HROne parity XO-3 (D-XO7): whether approvers are offered the "Suspected" decision (Setup centre, off by default). */
    public function suspicionOffered(): bool
    {
        return (bool) $this->settings->get('expense.review.suspect_button', false);
    }

    /**
     * HROne parity XO-3 (owner decision D-XO7 / EXP-13): decides a line an approver held out as suspected — approved up to
     * its claimable amount (blank = in full) or rejected (0) — with a note, by someone who may decide the line's part (never
     * their own report). The part's totals follow; a report already decided follows too (totals, advances settled against
     * what it now repays, net payable, the payroll schedule when there is no payment step). Releasing an amount needs a report
     * not yet paid and not carried by a payroll run or a final settlement; rejecting is always possible. Clearing works
     * whether or not the setting is still on, so nothing stays held when it is switched off.
     */
    public function clearSuspicion(ExpenseReceipt $receipt, float|int|string|null $amount, string $note, ?User $actor = null): ExpenseReceipt
    {
        $note = trim($note);
        if ($note === '') {
            throw new ExpenseRuleViolation('Say how the suspicion was cleared.');
        }
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new ExpenseRuleViolation('A suspicion is cleared by a person.');
        }

        return DB::transaction(function () use ($receipt, $amount, $note, $actor) {
            $reportId = ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->whereKey($receipt->getKey())->value('expense_report_id')
                ?? throw new ExpenseRuleViolation('That expense is not in a report.');
            $report = $this->lockReport(ExpenseReport::query()->withoutGlobalScope(AccessScope::class)->findOrFail($reportId));
            $line = ExpenseReceipt::query()->withoutGlobalScope(AccessScope::class)->whereKey($receipt->getKey())->where('expense_report_id', $report->id)->lockForUpdate()->first()
                ?? throw new ExpenseRuleViolation('That expense is not in this report.');
            if (! $line->isHeldAsSuspected() || $line->expense_report_part_id === null) {
                throw new ExpenseRuleViolation("{$line->number} is not held as suspected.");
            }
            if (! in_array($report->status, ['submitted', 'paid', ...ExpenseReport::DECIDED], true)) {
                throw new ExpenseRuleViolation('This report is '.strtolower(ExpenseReport::STATUSES[$report->status] ?? $report->status).': its expenses are not decided now.');
            }
            $part = ExpenseReportPart::query()->withoutGlobalScope(AccessScope::class)->whereKey($line->expense_report_part_id)->lockForUpdate()->firstOrFail();
            if (! app(ExpensePolicy::class)->approve($actor, $part)) {
                throw new ExpenseRuleViolation((int) $this->employee($report)->user_id === (int) $actor->id ? 'You cannot decide your own expense report.' : 'You cannot decide this expense report.');
            }
            $claimable = (float) $line->claimable_amount;
            $value = $amount === null || $amount === '' ? $claimable : (is_numeric($amount) ? round((float) $amount, 2) : -1);
            if ($value < 0 || $value > $claimable + 0.004) {
                throw new ExpenseRuleViolation("{$line->number}: approve between 0 and the claimable ".number_format($claimable, 2).'.');
            }
            if ($value > 0 && $report->paid_on !== null) {
                throw new ExpenseRuleViolation("{$report->number} is already paid: the held expense can only be rejected now.");
            }
            // EX-E: a report a payroll run or a final settlement already carries is paid as it was calculated, never changed under it.
            if ($value > 0 && ($where = $this->commitments->of(PayrollCommitments::REPORT, (int) $report->employee_id, (int) $report->id)) !== null) {
                throw new ExpenseRuleViolation("{$report->number} is being paid in {$where}: release the held expense once that is finished, or reject it.");
            }
            $line->withAuditReason('Suspicion cleared: '.$note)->update(['approved_amount' => $value, 'approver_note' => self::short($note),
                'status' => $value <= 0 ? 'rejected' : (abs($value - $claimable) < 0.005 ? 'approved' : 'partly_approved'),
                'suspicion_cleared_at' => now(), 'suspicion_cleared_by' => $actor->id]);
            $partLines = $this->partLines($part);
            $partTotal = round((float) $partLines->sum(fn (ExpenseReceipt $r) => (float) $r->approved_amount), 2);
            $part->withAuditReason('Suspicion cleared on '.$line->number)->update(['total_approved' => $partTotal,
                'status' => $this->decision($partTotal, $partLines)]);
            $this->audit->record($value > 0 ? AuditAction::Approved : AuditAction::Rejected, 'expense', $report, reason: $note, actor: $actor,
                metadata: ['report' => $report->number, 'receipt' => $line->number, 'suspicion' => 'cleared', 'approved' => $value]);
            if (in_array($report->status, ExpenseReport::DECIDED, true)) {
                $this->resettle($report, $actor, $line);
            }

            return $line->refresh();
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
                $receipt->withAuditReason($note)->update(['expense_report_part_id' => null, 'approved_amount' => null, 'approver_note' => null, 'status' => 'reported'] + self::NO_SUSPICION);
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
                $receipt->withAuditReason($reason)->update(['expense_report_id' => null, 'expense_report_part_id' => null, 'workflow_group' => null, 'approved_amount' => null, 'approver_note' => null, 'status' => 'unreported'] + self::NO_SUSPICION);
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

    /**
     * Pay expenses: an approved report's net payable was paid outside payroll; its reference and date are recorded.
     *
     * HROne parity XO-4 (§26.4.4 #18, #19): `$payment` may add how it was paid — `mode` (bank_transfer, cheque, cash),
     * `account_id` (an active paying bank account of the employee's company; not for cash), `cheque_number` and
     * `cheque_date` (required for a cheque, only for a cheque) and `note`. Nothing given = recorded as before.
     *
     * @param  array{mode?: ?string, account_id?: int|string|null, cheque_number?: ?string, cheque_date?: CarbonInterface|string|null, note?: ?string}  $payment
     */
    public function markPaid(ExpenseReport $report, string $reference, CarbonInterface|string $on, ?User $actor = null, array $payment = []): ExpenseReport
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

        return DB::transaction(function () use ($report, $reference, $on, $actor, $payment) {
            $locked = $this->lockReport($report);
            if ($actor === null || ! app(ExpensePolicy::class)->pay($actor, $locked)) {
                throw new ExpenseRuleViolation('You cannot record payments for this report.');
            }
            if (! $locked->isPayable()) {
                throw new ExpenseRuleViolation('Only an approved report with an amount to pay, not yet paid, can be marked paid.');
            }
            $details = $this->paymentDetails($locked, $payment);
            if ($locked->decided_at !== null && $on->lt($locked->decided_at->copy()->startOfDay())) {
                throw new ExpenseRuleViolation('A report is paid on or after its approval.');
            }
            // EX-E: a report a payroll run or a final settlement already carries is paid by it, never twice.
            if (($where = $this->commitments->of(PayrollCommitments::REPORT, (int) $locked->employee_id, (int) $locked->id)) !== null) {
                throw new ExpenseRuleViolation("{$locked->number} is already being paid in {$where}: it cannot be paid outside payroll as well.");
            }
            $locked->withAuditReason('Paid outside payroll: '.$reference)->update(['status' => 'paid', 'payment_channel' => 'bank', 'payment_scheduled_at' => null, 'payment_scheduled_by' => null,
                'paid_on' => $on->toDateString(), 'payment_reference' => $reference, 'paid_by' => $actor->id] + ($locked->payment_scheduled_for !== null ? ['payment_scheduled_for' => null] : []) + $details);
            $this->tell('expense.report_paid', $locked, ['amount' => (float) $locked->net_payable, 'reference' => $reference, 'paid_on' => $on->toDateString()]
                + (isset($details['payment_mode']) ? ['mode' => ExpenseReport::PAYMENT_MODES[$details['payment_mode']]] : []));

            return $locked->refresh();
        });
    }

    /**
     * EX-E (Pay expenses → Pay with payroll): an approved report waiting for payment is scheduled for payroll; the run whose
     * period covers today pays it when it is finalized. Pay expenses may still pay it outside payroll until a run carries it.
     *
     * HROne parity XO-4 (D-XO1): `$month` ("Y-m") names the payroll month instead — an open month of the employee's company
     * that is not finalized; only that month's payroll pays it. Blank = the next payroll, as before. `$note` is kept with
     * the payment.
     */
    public function scheduleForPayroll(ExpenseReport $report, ?User $actor = null, ?string $month = null, ?string $note = null): ExpenseReport
    {
        $actor ??= auth()->user();
        $note = self::paymentNote($note);

        return DB::transaction(function () use ($report, $actor, $month, $note) {
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
            $for = $this->payMonth($locked, $month);
            $locked->withAuditReason('Scheduled for payroll'.($for !== null ? ' of '.CarbonImmutable::parse($for)->format('M Y') : ''))
                ->update(['payment_channel' => 'payroll', 'payment_scheduled_at' => now(), 'payment_scheduled_by' => $actor->id]
                    + ($for !== null ? ['payment_scheduled_for' => $for] : []) + ($note !== null ? ['payment_note' => $note] : []));
            $this->tell('expense.report_scheduled', $locked, ['amount' => (float) $locked->net_payable, 'channel' => 'payroll']
                + ($for !== null ? ['month' => CarbonImmutable::parse($for)->format('M Y')] : []));

            return $locked->refresh();
        });
    }

    /**
     * HROne parity XO-4 (D-XO1): moves a report scheduled for payroll to another payroll month (blank = the next payroll).
     * Refused once a payroll run carries it — it is paid by that run when it is finalized, never in a second month.
     */
    public function reschedulePayroll(ExpenseReport $report, ?string $month, ?User $actor = null, ?string $note = null): ExpenseReport
    {
        $actor ??= auth()->user();
        $note = self::paymentNote($note);

        return DB::transaction(function () use ($report, $month, $actor, $note) {
            $locked = $this->lockScheduled($report, $actor);
            $for = $this->payMonth($locked, $month);
            $before = $locked->payMonthLabel();
            if ($for === ($locked->payment_scheduled_for?->toDateString())) {
                throw new ExpenseRuleViolation("{$locked->number} is already scheduled for ".($for === null ? 'the next payroll' : CarbonImmutable::parse($for)->format('M Y')).'.');
            }
            $after = $for === null ? 'Next payroll' : CarbonImmutable::parse($for)->format('M Y');
            $locked->withAuditReason("Payroll month changed: {$before} → {$after}")->update(['payment_scheduled_for' => $for, 'payment_scheduled_at' => now(), 'payment_scheduled_by' => $actor->id]
                + ($note !== null ? ['payment_note' => $note] : []));
            $this->tell('expense.report_scheduled', $locked, ['amount' => (float) $locked->net_payable, 'channel' => 'payroll'] + ($for !== null ? ['month' => $after] : []));

            return $locked->refresh();
        });
    }

    /**
     * HROne parity XO-4: takes a report off payroll (back to waiting on Pay expenses). Refused once a payroll run carries it.
     */
    public function cancelPayrollSchedule(ExpenseReport $report, ?string $reason = null, ?User $actor = null): ExpenseReport
    {
        $actor ??= auth()->user();
        $reason = filled($reason) ? trim((string) $reason) : null;

        return DB::transaction(function () use ($report, $reason, $actor) {
            $locked = $this->lockScheduled($report, $actor);
            $locked->withAuditReason('Payroll schedule cancelled'.($reason !== null ? ': '.$reason : ''))
                ->update(['payment_channel' => null, 'payment_scheduled_at' => null, 'payment_scheduled_by' => null, 'payment_scheduled_for' => null]);

            return $locked->refresh();
        });
    }

    /**
     * HROne parity XO-4: whether the payroll month chosen for a scheduled report was finalized without it (its run was
     * calculated before it was scheduled, or the employee was not paid that month). It stays scheduled for that month —
     * no other month takes it — until it is moved to another month, paid outside payroll or taken off payroll.
     */
    public function payMonthMissed(ExpenseReport $report): bool
    {
        return $report->isScheduledForPayroll() && ($month = $report->payMonth()) !== null && app(PayrollMonths::class)->isFinalized((int) $report->employee_id, $month);
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
        $status = $this->decision($approved, $lines);
        $adjusted = $status === 'rejected' ? 0.0 : $this->advances->adjustForReport($report, $reimbursable, $actor);
        $net = round($reimbursable - $adjusted, 2);
        // EX-E: without a payment step an approved report goes straight to the next payroll.
        $toPayroll = $status !== 'rejected' && $net > 0 && ! (bool) $this->settings->get('expense.payment.step_required', true);
        $report->withAuditReason('Every part decided')->update(['status' => $status, 'total_approved' => $approved, 'reimbursable_approved' => $reimbursable,
            'advance_adjusted' => $adjusted, 'net_payable' => $net, 'decided_at' => now(), 'decided_by' => $actor->id]
            + ($toPayroll ? ['payment_channel' => 'payroll', 'payment_scheduled_at' => now(), 'payment_scheduled_by' => null] : []));
        $this->tell('expense.report_'.$status, $report, ['approved' => $approved, 'advance_adjusted' => $adjusted, 'net_payable' => round($reimbursable - $adjusted, 2)]);
    }

    /**
     * The decision its lines make: nothing approved is a rejection — unless a line is held as suspected (XO-3), which
     * leaves it partly approved until the suspicion is cleared; every line approved in full is approved; else partly.
     */
    private function decision(float $approved, BaseCollection $lines): string
    {
        return match (true) {
            $approved <= 0 && ! $lines->contains(fn (ExpenseReceipt $r) => $r->isHeldAsSuspected()) => 'rejected',
            $approved <= 0 => 'partly_approved',
            $lines->every(fn (ExpenseReceipt $r) => $r->status === 'approved') => 'approved',
            default => 'partly_approved',
        };
    }

    /**
     * HROne parity XO-3: a decided report follows a line decided after it (a suspicion cleared) — its totals and status, the
     * employee's dispensed advances settled against what it now repays (oldest first, once per line), its net payable and,
     * without a payment step, the payroll schedule. The caller made sure no payment carries it.
     */
    private function resettle(ExpenseReport $report, User $actor, ExpenseReceipt $line): void
    {
        $lines = $this->lines($report);
        $approved = round((float) $lines->sum(fn (ExpenseReceipt $r) => (float) $r->approved_amount), 2);
        $reimbursable = round((float) $lines->filter(fn (ExpenseReceipt $r) => $r->reimbursable)->sum(fn (ExpenseReceipt $r) => (float) $r->approved_amount), 2);
        $status = $this->decision($approved, $lines);
        $more = round($reimbursable - (float) $report->reimbursable_approved, 2);
        $adjusted = round((float) $report->advance_adjusted + ($status !== 'rejected' && $more > 0 && $report->paid_on === null
            ? $this->advances->adjustForReport($report, $more, $actor, 'report-'.$report->id.'-receipt-'.$line->id) : 0.0), 2);
        $net = round($reimbursable - $adjusted, 2);
        $toPayroll = $status !== 'rejected' && $net > 0 && $report->payment_channel === null && ! (bool) $this->settings->get('expense.payment.step_required', true);
        $before = $report->status;
        $report->withAuditReason('Suspicion cleared on '.$line->number)->update(['status' => $status, 'total_approved' => $approved, 'reimbursable_approved' => $reimbursable,
            'advance_adjusted' => $adjusted, 'net_payable' => $net]
            + ($toPayroll ? ['payment_channel' => 'payroll', 'payment_scheduled_at' => now(), 'payment_scheduled_by' => null] : []));
        if ($before !== $status || $more > 0) {
            $this->tell('expense.report_'.$status, $report, ['approved' => $approved, 'advance_adjusted' => $adjusted, 'net_payable' => $net]);
        }
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

    /** XO-4: the report, locked, scheduled for payroll and not yet carried by a run or a final settlement. */
    private function lockScheduled(ExpenseReport $report, ?User $actor): ExpenseReport
    {
        $locked = $this->lockReport($report);
        if ($actor === null || ! app(ExpensePolicy::class)->pay($actor, $locked)) {
            throw new ExpenseRuleViolation('You cannot record payments for this report.');
        }
        if (! $locked->isScheduledForPayroll()) {
            throw new ExpenseRuleViolation("{$locked->number} is not scheduled for payroll.");
        }
        if (($where = $this->commitments->of(PayrollCommitments::REPORT, (int) $locked->employee_id, (int) $locked->id)) !== null) {
            throw new ExpenseRuleViolation("{$locked->number} is already being paid in {$where}: it stays with that payroll.");
        }

        return $locked;
    }

    /**
     * XO-4 (D-XO1): the payroll month chosen ("Y-m") as the first day of the month, or null for the next payroll. Only an
     * open month of the employee's company that is not finalized may be chosen.
     */
    private function payMonth(ExpenseReport $report, ?string $month): ?string
    {
        $month = trim((string) $month);
        if ($month === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw new ExpenseRuleViolation('Choose the payroll month that pays it.');
        }
        $label = CarbonImmutable::create((int) $m[1], (int) $m[2], 1)->format('M Y');
        $months = app(PayrollMonths::class);
        if ($months->isFinalized((int) $report->employee_id, $month)) {
            throw new ExpenseRuleViolation("The payroll of {$label} is finalized: choose a month not yet finalized.");
        }
        $open = $months->openFor((int) $report->employee_id);
        if ($open === []) {
            throw new ExpenseRuleViolation("{$report->number}: the employee has no company to run payroll for; pay it with the next payroll or outside payroll.");
        }
        if (! array_key_exists($month, $open)) {
            $keys = array_keys($open);
            $range = CarbonImmutable::parse(reset($keys).'-01')->format('M Y').' to '.CarbonImmutable::parse(end($keys).'-01')->format('M Y');

            throw new ExpenseRuleViolation("{$label} is not an open payroll month for {$report->number}: choose from {$range}.");
        }

        return sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
    }

    /**
     * XO-4 (§26.4.4 #18, #19): an outside payment's mode, paying account, cheque details and note, checked; only the
     * columns given are returned (nothing given = nothing recorded, as before).
     *
     * @return array<string, mixed>
     */
    private function paymentDetails(ExpenseReport $report, array $payment): array
    {
        $mode = filled($payment['mode'] ?? null) ? (string) $payment['mode'] : null;
        if ($mode !== null && ! array_key_exists($mode, ExpenseReport::PAYMENT_MODES)) {
            throw new ExpenseRuleViolation('Choose how it was paid: bank transfer, cheque or cash.');
        }
        $account = filled($payment['account_id'] ?? null) ? (is_numeric($payment['account_id']) ? (int) $payment['account_id'] : -1) : null;
        $chequeNumber = filled($payment['cheque_number'] ?? null) ? trim((string) $payment['cheque_number']) : null;
        $chequeDate = $payment['cheque_date'] ?? null;
        $chequeDate = filled($chequeDate) ? $chequeDate : null;
        $details = [];
        if ($mode !== null) {
            $details['payment_mode'] = $mode;
        }
        if ($account !== null) {
            if ($mode === null || $mode === 'cash') {
                throw new ExpenseRuleViolation($mode === 'cash' ? 'A cash payment has no paying bank account.' : 'Say how it was paid before naming the paying account.');
            }
            if (! array_key_exists($account, app(PayrollMonths::class)->payingAccounts((int) $report->employee_id))) {
                throw new ExpenseRuleViolation('Choose an active paying bank account of the employee\'s company.');
            }
            $details['paid_from_account_id'] = $account;
        }
        if ($mode === 'cheque') {
            if ($chequeNumber === null || mb_strlen($chequeNumber) > 30) {
                throw new ExpenseRuleViolation('Give the cheque number (at most 30 characters).');
            }
            if ($chequeDate === null) {
                throw new ExpenseRuleViolation('Give the cheque date.');
            }
            try {
                $details['cheque_date'] = CarbonImmutable::parse($chequeDate)->toDateString();
            } catch (\Throwable) {
                throw new ExpenseRuleViolation('Give the cheque date.');
            }
            $details['cheque_number'] = $chequeNumber;
        } elseif ($chequeNumber !== null || $chequeDate !== null) {
            throw new ExpenseRuleViolation('Cheque number and date go with a payment by cheque.');
        }
        if (($note = self::paymentNote($payment['note'] ?? null)) !== null) {
            $details['payment_note'] = $note;
        }

        return $details;
    }

    /** XO-4: the payment note, trimmed (at most 500 characters), or null. */
    private static function paymentNote(?string $note): ?string
    {
        $note = trim((string) $note);
        if (mb_strlen($note) > 500) {
            throw new ExpenseRuleViolation('Keep the payment note to 500 characters.');
        }

        return $note === '' ? null : $note;
    }

    /** Notes are kept to their column (500 characters); the audit trail keeps the full text. */
    private static function short(?string $note): ?string
    {
        return $note === null ? null : mb_substr(trim($note), 0, 500);
    }
}
