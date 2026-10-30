<?php

namespace App\Domain\Expense\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Events\ExpenseEvent;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Policies\ExpensePolicy;
use App\Domain\Expense\Support\ExpenseAdvanceRules;
use App\Domain\Expense\Support\ExpenseCommitments;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Support\Numbering\NumberSeries;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * HROne setup parity EX-D: expense advances under the employee's expense advance policy (ExpenseAdvanceRules).
 *
 * - Request: refused without a policy that offers advances (HROne's "Expense advance policy needs to be configured …"),
 *   above the largest advance, beyond the open advances allowed or the most outstanding across them; purpose and
 *   attachment when the policy asks. Requests start `expense.advance_requested` workflows, else wait in the Approval
 *   Center.
 * - Approve (possibly for less) or reject, in scope and never one's own. Cancel: a request at once; an approved advance
 *   after approval when the policy says so (`expense.advance_cancel_requested`); a dispensed one never (it is settled).
 * - Dispense (`expense.pay`): records the outside payment's reference and date — PeopleOS pays nothing — posts the ledger
 *   (+) and sets the settle-by date (policy: days after payment) and, when the policy recovers through payroll, the
 *   recovery date (settle-by plus the policy's days).
 * - EX-E: an advance approved for payroll payout is paid by the next payroll (`paidOut` when that run is finalized);
 *   what is outstanding from the recovery date is recovered by payroll (`recovered`), and at exit by the final
 *   settlement. While a payroll run not yet finalized or an unpaid final settlement carries an advance
 *   (ExpenseCommitments), it is not dispensed, cancelled, refunded, written off or adjusted against a report.
 * - Settle: an approved expense report adjusts the employee's dispensed advances oldest first (ledger −, keyed by the
 *   report); refunds and write-offs (`expense.pay`) close what is left.
 * - EX-F: an advance asked for inside a travel request (`travel_request_id`) raises no workflow of its own: the trip's
 *   approval approves it, its rejection rejects it and its cancellation cancels it (unless it is already paid). Its
 *   settle-by date counts from the trip's end when that is after the payment.
 */
final class ExpenseAdvances
{
    public function __construct(
        private readonly ExpensePolicies $policies,
        private readonly CurrencyRates $rates,
        private readonly NumberSeries $numbers,
        private readonly AuditRecorder $audit,
        private readonly ExpenseCommitments $commitments,
    ) {}

    public static function directory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/expense/advances';
    }

    /** @param  array<string, mixed>  $data  amount, currency, purpose, needed_on, attachments, travel_request_id (EX-F: decided with the trip) */
    public function request(Employee $employee, array $data, ?User $actor = null): ExpenseAdvance
    {
        $actor = $this->authorise($actor, $employee);
        // HROne setup parity P4: the employee request lock refuses the employee's own request while it applies.
        app(SelfServiceLock::class)->assertOpen($employee, 'expense', $actor, ExpenseRuleViolation::class);
        $tripId = filled($data['travel_request_id'] ?? null) ? (int) $data['travel_request_id'] : null;
        if ($tripId !== null && ! TravelRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey($tripId)->where('employee_id', $employee->id)->exists()) {
            throw new ExpenseRuleViolation('An advance can be asked for only with one of the employee\'s own travel requests.');
        }
        $today = CarbonImmutable::today();
        $rules = $this->policies->advanceRules($employee, $today);
        if (($refusal = $rules->refusal()) !== null) {
            throw new ExpenseRuleViolation($refusal);
        }
        $base = $this->rates->baseCurrency();
        $currency = strtoupper(trim((string) ($data['currency'] ?? ''))) ?: $base;
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ExpenseRuleViolation('Give the currency as a three-letter code, e.g. INR or USD.');
        }
        $amount = is_numeric($data['amount'] ?? null) ? round((float) $data['amount'], 2) : 0.0;
        try {
            $fx = $this->rates->rate($currency, $base, $today);
        } catch (RuntimeException) {
            throw new ExpenseRuleViolation("There is no exchange rate from {$currency} to {$base} today. Ask HR to add it under Exchange rates.");
        }
        $amountBase = round($amount * $fx, 2);
        if (($refusal = $rules->refusalFor($amountBase)) !== null) {
            throw new ExpenseRuleViolation($refusal);
        }
        $purpose = filled($data['purpose'] ?? null) ? mb_substr(trim((string) $data['purpose']), 0, 500) : null;
        if ($rules->purposeRequired && $purpose === null) {
            throw new ExpenseRuleViolation('Give the purpose of the advance.');
        }
        $attachments = $this->files((array) ($data['attachments'] ?? []));
        if ($rules->attachmentRequired && $attachments === []) {
            throw new ExpenseRuleViolation('Attach a supporting document: your expense advance policy needs one.');
        }
        $neededOn = filled($data['needed_on'] ?? null) ? CarbonImmutable::parse($data['needed_on'])->toDateString() : null;
        $this->numbers->prepare('expense_advance', NumberSeries::companyOf($employee));

        return DB::transaction(function () use ($employee, $actor, $rules, $currency, $amount, $fx, $amountBase, $purpose, $attachments, $neededOn, $tripId) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->value('id');
            $open = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->whereIn('status', ExpenseAdvance::OPEN)->get();
            if ($open->count() >= $rules->maxOpen) {
                throw new ExpenseRuleViolation("Your expense advance policy allows {$rules->maxOpen} open advance(s) at a time: settle or cancel one first.");
            }
            if ($rules->maxOutstandingTotal !== null) {
                $exposure = round((float) $open->sum(fn (ExpenseAdvance $a) => $a->status === 'disbursed' ? (float) $a->outstanding : $a->baseAmount()), 2);
                if ($exposure + $amountBase > $rules->maxOutstandingTotal + 0.004) {
                    throw new ExpenseRuleViolation('Your expense advance policy allows at most '.number_format($rules->maxOutstandingTotal, 2).' across open advances; '.number_format($exposure, 2).' is already open.');
                }
            }
            $advance = new ExpenseAdvance([
                'number' => $this->numbers->next('expense_advance', NumberSeries::companyOf($employee)), 'employee_id' => $employee->id, 'policy_version_id' => $rules->policyVersionId, 'travel_request_id' => $tripId,
                'purpose' => $purpose, 'needed_on' => $neededOn, 'currency' => $currency, 'amount' => $amount, 'exchange_rate' => round($fx, 8), 'amount_base' => $amountBase,
                'attachments' => $attachments ?: null, 'status' => 'requested', 'disbursement_channel' => $rules->disbursement, 'requested_by' => $actor->id,
            ]);
            $advance->withAuditReason($purpose)->save();
            $this->audit->record(AuditAction::Submitted, 'expense', $advance, reason: $purpose, actor: $actor, metadata: ['amount' => $amountBase] + ($tripId !== null ? ['travel_request_id' => $tripId] : []));
            if ($tripId === null) {
                // EX-F: an advance inside a travel request is decided with the trip (its workflow), not on its own.
                ExpenseEvent::dispatch('expense.advance_requested', $employee, $advance, ['number' => $advance->number, 'amount' => $amountBase, 'currency' => $this->rates->baseCurrency()], $this->managerUsers($employee));
            }

            return $advance;
        });
    }

    public function approve(ExpenseAdvance $advance, ?float $amount = null, ?string $note = null, ?User $actor = null): ExpenseAdvance
    {
        return DB::transaction(function () use ($advance, $amount, $note, $actor) {
            [$locked, $actor] = $this->decidable($advance, $actor, 'requested', 'approve');
            $approved = round($amount ?? (float) $locked->amount_base, 2);
            if ($approved <= 0 || $approved > (float) $locked->amount_base + 0.004) {
                throw new ExpenseRuleViolation('Approve between 0.01 and the amount asked for ('.number_format((float) $locked->amount_base, 2).').');
            }
            $locked->withAuditReason($note)->update(['status' => 'approved', 'approved_amount' => $approved, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => self::short($note)]);
            $this->audit->record(AuditAction::Approved, 'expense', $locked, reason: $note, actor: $actor, metadata: ['approved' => $approved]);
            $this->tell('expense.advance_approved', $locked, ['approved' => $approved]);

            return $locked;
        });
    }

    public function reject(ExpenseAdvance $advance, string $note, ?User $actor = null): ExpenseAdvance
    {
        $this->assertNote($note, 'rejecting');

        return DB::transaction(function () use ($advance, $note, $actor) {
            [$locked, $actor] = $this->decidable($advance, $actor, 'requested', 'approve');
            $locked->withAuditReason($note)->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => self::short($note), 'closed_at' => now()]);
            $this->audit->record(AuditAction::Rejected, 'expense', $locked, reason: $note, actor: $actor);
            $this->tell('expense.advance_rejected', $locked, ['note' => $note]);

            return $locked;
        });
    }

    /**
     * A request is cancelled at once. An approved advance not yet dispensed is cancelled at once by an approver, or by
     * the employee when their policy needs no approval; otherwise the cancellation waits for approval.
     */
    public function cancel(ExpenseAdvance $advance, string $reason, ?User $actor = null): ExpenseAdvance
    {
        $this->assertNote($reason, 'cancelling');
        $actor ??= auth()->user();
        if ($actor === null || ! app(ExpensePolicy::class)->update($actor, $advance)) {
            throw new ExpenseRuleViolation('You cannot cancel this advance.');
        }

        return DB::transaction(function () use ($advance, $reason, $actor) {
            $locked = $this->lock($advance);
            if ($locked->status === 'approved') {
                $this->assertFree($locked, PayrollCommitments::PAYOUT, 'cancelled');
            }
            $byApprover = app(ExpensePolicy::class)->approve($actor, $locked);
            if ($locked->status === 'requested' || ($locked->status === 'approved' && ($byApprover || ! $this->rulesFor($locked)->cancellationNeedsApproval))) {
                $locked->withAuditReason($reason)->update(['status' => 'cancelled', 'cancel_reason' => self::short($reason), 'closed_at' => now()]);
                $this->audit->record(AuditAction::Cancelled, 'expense', $locked, reason: $reason, actor: $actor);
                $this->tell('expense.advance_cancelled', $locked, ['reason' => $reason]);

                return $locked;
            }
            if ($locked->status === 'approved') {
                $locked->withAuditReason($reason)->update(['status' => 'cancel_requested', 'cancel_reason' => self::short($reason), 'cancel_requested_at' => now()]);
                $this->audit->record(AuditAction::Submitted, 'expense', $locked, reason: $reason, actor: $actor, metadata: ['cancellation' => true]);
                ExpenseEvent::dispatch('expense.advance_cancel_requested', $locked->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail(), $locked,
                    ['number' => $locked->number, 'amount' => $locked->baseAmount(), 'reason' => $reason], $this->managerUsers($locked->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail()));

                return $locked;
            }

            throw new ExpenseRuleViolation(in_array($locked->status, ['disbursed', 'settled', 'written_off'], true)
                ? 'A dispensed advance cannot be cancelled: it is settled with expense reports, a refund or a write-off.'
                : 'This advance can no longer be cancelled.');
        });
    }

    public function approveCancellation(ExpenseAdvance $advance, ?string $note = null, ?User $actor = null): ExpenseAdvance
    {
        return DB::transaction(function () use ($advance, $note, $actor) {
            [$locked, $actor] = $this->decidable($advance, $actor, 'cancel_requested', 'approve');
            $this->assertFree($locked, PayrollCommitments::PAYOUT, 'cancelled');
            $locked->withAuditReason($note)->update(['status' => 'cancelled', 'closed_at' => now(), 'decision_note' => self::short($note)]);
            $this->audit->record(AuditAction::Approved, 'expense', $locked, reason: $note, actor: $actor, metadata: ['cancellation' => true]);
            $this->tell('expense.advance_cancelled', $locked, ['reason' => $locked->cancel_reason]);

            return $locked;
        });
    }

    public function rejectCancellation(ExpenseAdvance $advance, string $note, ?User $actor = null): ExpenseAdvance
    {
        $this->assertNote($note, 'keeping the advance');

        return DB::transaction(function () use ($advance, $note, $actor) {
            [$locked, $actor] = $this->decidable($advance, $actor, 'cancel_requested', 'approve');
            // HROne parity XO-1: the refused cancellation is recorded (Advance cancellations keeps listing it), never removed.
            $locked->withAuditReason($note)->update(['status' => 'approved', 'cancel_requested_at' => null, 'cancel_refused_at' => now(), 'decision_note' => self::short($note)]);
            $this->audit->record(AuditAction::Rejected, 'expense', $locked, reason: $note, actor: $actor, metadata: ['cancellation' => true]);

            return $locked;
        });
    }

    /** Records that the approved advance was paid outside PeopleOS (bank transfer, cash) with its reference. */
    public function dispense(ExpenseAdvance $advance, string $reference, CarbonInterface|string $on, ?User $actor = null): ExpenseAdvance
    {
        $reference = $this->reference($reference);
        $on = $this->paymentDate($on);

        return DB::transaction(function () use ($advance, $reference, $on, $actor) {
            [$locked, $actor] = $this->decidable($advance, $actor, 'approved', 'pay');
            if ($on->lt(CarbonImmutable::parse($locked->decided_at ?? $locked->created_at)->startOfDay())) {
                throw new ExpenseRuleViolation('An advance is dispensed on or after its approval.');
            }
            // EX-E: an advance a payroll run has already calculated its payout for is paid by that run.
            $this->assertFree($locked, PayrollCommitments::PAYOUT, 'dispensed outside payroll');
            $amount = (float) $locked->approved_amount;
            $this->post($locked, 'disbursed', $amount, 'disbursed', $on, $reference, 'Dispensed outside payroll', null, $actor);
            $rules = $this->rulesFor($locked);
            $settleBy = $this->settleFrom($locked, $on)->addDays($rules->settleWithinDays);
            $locked->withAuditReason('Dispensed: '.$reference)->update(['status' => 'disbursed', 'disbursement_channel' => 'bank', 'disbursed_on' => $on->toDateString(),
                'disbursement_reference' => $reference, 'disbursed_by' => $actor->id, 'settle_by' => $settleBy->toDateString(), 'recovery_due_on' => self::recoveryDueOn($rules, $settleBy)]);
            $this->tell('expense.advance_dispensed', $locked, ['amount' => $amount, 'reference' => $reference, 'settle_by' => $settleBy->toDateString()]);

            return $locked->refresh();
        });
    }

    /** The employee paid back (part of) a dispensed advance. */
    public function refund(ExpenseAdvance $advance, float $amount, string $reference, CarbonInterface|string $on, ?User $actor = null): ExpenseAdvance
    {
        $reference = $this->reference($reference);
        $on = $this->paymentDate($on);

        return DB::transaction(function () use ($advance, $amount, $reference, $on, $actor) {
            [$locked, $actor] = $this->decidable($advance, $actor, 'disbursed', 'pay');
            $this->assertFree($locked, PayrollCommitments::RECOVERY, 'refunded');
            $amount = round($amount, 2);
            if ($amount <= 0 || $amount > (float) $locked->outstanding + 0.004) {
                throw new ExpenseRuleViolation('A refund is between 0.01 and what is outstanding ('.number_format((float) $locked->outstanding, 2).').');
            }
            if ($this->post($locked, 'refunded', -$amount, 'refund-'.substr(sha1(mb_strtolower($reference)), 0, 24), $on, $reference, 'Refunded by the employee', null, $actor) === null) {
                throw new ExpenseRuleViolation("A refund with the reference {$reference} is already recorded.");
            }
            $this->closeIfSettled($locked->refresh());

            return $locked->refresh();
        });
    }

    /** What is left of a dispensed advance is written off (not recovered), with the reason. */
    public function writeOff(ExpenseAdvance $advance, string $reason, ?User $actor = null): ExpenseAdvance
    {
        $this->assertNote($reason, 'writing off');

        return DB::transaction(function () use ($advance, $reason, $actor) {
            [$locked, $actor] = $this->decidable($advance, $actor, 'disbursed', 'pay');
            $this->assertFree($locked, PayrollCommitments::RECOVERY, 'written off');
            $outstanding = (float) $locked->outstanding;
            if ($outstanding <= 0) {
                throw new ExpenseRuleViolation('Nothing is outstanding on this advance.');
            }
            $this->post($locked, 'written_off', -$outstanding, 'write-off', CarbonImmutable::today(), null, mb_substr($reason, 0, 255), null, $actor);
            $locked->withAuditReason($reason)->update(['status' => 'written_off', 'closed_at' => now()]);
            $this->tell('expense.advance_written_off', $locked, ['amount' => $outstanding, 'reason' => $reason]);

            return $locked->refresh();
        });
    }

    /**
     * Settles the employee's dispensed advances against an approved report, oldest first, up to what the report repays.
     * Runs inside the report's decision (the caller holds the report lock). Returns the amount adjusted.
     */
    public function adjustForReport(ExpenseReport $report, float $available, ?User $actor = null): float
    {
        $remaining = round($available, 2);
        if ($remaining <= 0) {
            return 0.0;
        }
        $advances = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $report->employee_id)->where('status', 'disbursed')
            ->where('outstanding', '>', 0)->orderBy('disbursed_on')->orderBy('id')->lockForUpdate()->get();
        // EX-E: an advance a payroll run or a final settlement is already recovering is left to it.
        $recovering = $advances->isEmpty() ? [] : $this->commitments->pending(PayrollCommitments::RECOVERY, (int) $report->employee_id);
        $adjusted = 0.0;
        foreach ($advances as $advance) {
            if ($remaining <= 0) {
                break;
            }
            if (isset($recovering[$advance->id])) {
                continue;
            }
            $take = round(min($remaining, (float) $advance->outstanding), 2);
            if ($take <= 0 || $this->post($advance, 'adjusted', -$take, 'report-'.$report->id, CarbonImmutable::today(), $report->number, 'Settled against expense report '.$report->number, $report->id, $actor) === null) {
                continue;
            }
            $remaining = round($remaining - $take, 2);
            $adjusted = round($adjusted + $take, 2);
            $this->closeIfSettled($advance->refresh());
        }

        return $adjusted;
    }

    /**
     * EX-E: these approved advances were paid out with payroll (or, at exit, in the final settlement): ledger (+), dispensed
     * on the payment date, settle-by and recovery dates from the advance's policy. Called once the run is finalized (or the
     * settlement paid); an advance no longer approved is left alone.
     *
     * @param  list<int>  $ids
     */
    public function paidOut(array $ids, string $channel, CarbonInterface|string $on, string $reference, string $note, ?int $payrollRunId = null, ?int $settlementId = null): void
    {
        $on = CarbonImmutable::parse($on)->startOfDay();
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $channel, $on, $reference, $note, $payrollRunId, $settlementId) {
                $locked = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->lockForUpdate()->first();
                if ($locked === null || $locked->status !== 'approved') {
                    return;
                }
                $amount = (float) $locked->approved_amount;
                $this->post($locked, 'disbursed', $amount, 'disbursed', $on, $reference, mb_substr($note, 0, 255), null, null, ['payroll_run_id' => $payrollRunId, 'final_settlement_id' => $settlementId]);
                $rules = $this->rulesFor($locked);
                $settleBy = $this->settleFrom($locked, $on)->addDays($rules->settleWithinDays);
                $locked->withAuditReason($note)->update(['status' => 'disbursed', 'disbursement_channel' => $channel, 'disbursed_on' => $on->toDateString(), 'disbursement_reference' => mb_substr($reference, 0, 120),
                    'disbursed_payroll_run_id' => $payrollRunId, 'settle_by' => $settleBy->toDateString(), 'recovery_due_on' => self::recoveryDueOn($rules, $settleBy)]);
                $this->tell('expense.advance_dispensed', $locked, ['amount' => $amount, 'reference' => $reference, 'channel' => $channel, 'settle_by' => $settleBy->toDateString()]);
            });
        }
    }

    /**
     * EX-E: these amounts were recovered from dispensed advances by payroll (or the final settlement): ledger (−) keyed
     * $key (once per advance), recorded with the run or settlement; an advance with nothing left is `recovered`.
     *
     * @param  array<int, float>  $amounts  advance id => amount recovered
     */
    public function recovered(array $amounts, string $key, CarbonInterface|string $on, string $reference, string $note, ?int $payrollRunId = null, ?int $settlementId = null): void
    {
        $on = CarbonImmutable::parse($on)->startOfDay();
        foreach ($amounts as $id => $amount) {
            DB::transaction(function () use ($id, $amount, $key, $on, $reference, $note, $payrollRunId, $settlementId) {
                $locked = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->lockForUpdate()->first();
                if ($locked === null || round((float) $amount, 2) <= 0 || ! in_array($locked->status, ['disbursed', 'settled', 'recovered'], true)) {
                    return;
                }
                if ($this->post($locked, 'recovered', -round((float) $amount, 2), $key, $on, mb_substr($reference, 0, 120), mb_substr($note, 0, 255), null, null, ['payroll_run_id' => $payrollRunId, 'final_settlement_id' => $settlementId]) === null) {
                    return;
                }
                $locked->refresh();
                if ($locked->status === 'disbursed' && (float) $locked->outstanding <= 0) {
                    $locked->withAuditReason($note)->update(['status' => 'recovered', 'closed_at' => now()]);
                }
                $this->tell('expense.advance_recovered', $locked, ['amount' => round((float) $amount, 2), 'reference' => $reference]);
            });
        }
    }

    /**
     * EX-F: the trip's advance is cancelled with the trip — a request or an approved advance not yet paid (nor carried by a
     * payroll run or a final settlement). A paid advance stays: it is settled with reports, a refund or recovery. Runs
     * inside the trip's cancellation (the caller holds the trip lock).
     */
    public function cancelWithTrip(ExpenseAdvance $advance, string $reason): ?ExpenseAdvance
    {
        $locked = $this->lock($advance);
        if (! in_array($locked->status, ['requested', 'approved', 'cancel_requested'], true)
            || $this->commitments->of(PayrollCommitments::PAYOUT, (int) $locked->employee_id, (int) $locked->id) !== null) {
            return null;
        }
        $locked->withAuditReason($reason)->update(['status' => 'cancelled', 'cancel_reason' => self::short($reason), 'closed_at' => now()]);
        $this->tell('expense.advance_cancelled', $locked, ['reason' => $reason]);

        return $locked;
    }

    /** The day payroll may start recovering what is outstanding: settle-by plus the policy's days, when it recovers through payroll. */
    public static function recoveryDueOn(ExpenseAdvanceRules $rules, CarbonInterface $settleBy): ?string
    {
        return $rules->recovery === 'payroll' ? CarbonImmutable::parse($settleBy)->addDays($rules->recoveryAfterDays)->toDateString() : null;
    }

    /** The employee's advance policy as it applied to this advance (its policy version; today's when none was recorded). */
    public function rulesFor(ExpenseAdvance $advance): ExpenseAdvanceRules
    {
        $version = $advance->policy_version_id !== null ? PolicyVersion::query()->find($advance->policy_version_id) : null;
        if ($version !== null) {
            return ExpenseAdvanceRules::from($version);
        }

        return $this->policies->advanceRules(Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($advance->employee_id), CarbonImmutable::today());
    }

    /** EX-F: settle-by counts from the payment, or from the end of the advance's trip when that is later. */
    private function settleFrom(ExpenseAdvance $advance, CarbonImmutable $paidOn): CarbonImmutable
    {
        $tripEnd = $advance->travel_request_id !== null ? TravelRequest::query()->withoutGlobalScope(AccessScope::class)->whereKey($advance->travel_request_id)->value('end_date') : null;

        return $tripEnd !== null && CarbonImmutable::parse($tripEnd)->startOfDay()->gt($paidOn) ? CarbonImmutable::parse($tripEnd)->startOfDay() : $paidOn;
    }

    /** Posts a ledger entry once (by key) and refreshes the cached outstanding amount. */
    private function post(ExpenseAdvance $advance, string $kind, float $amount, string $key, CarbonInterface $on, ?string $reference, ?string $note, ?int $reportId, ?User $actor, array $extra = []): ?ExpenseAdvanceEntry
    {
        if (ExpenseAdvanceEntry::query()->withoutGlobalScope(AccessScope::class)->where('expense_advance_id', $advance->id)->where('entry_key', $key)->exists()) {
            return null;
        }
        $entry = ExpenseAdvanceEntry::query()->create(['expense_advance_id' => $advance->id, 'employee_id' => $advance->employee_id, 'kind' => $kind, 'amount' => round($amount, 2),
            'expense_report_id' => $reportId, 'entry_key' => $key, 'entry_date' => $on->toDateString(), 'reference' => $reference, 'note' => $note, 'created_by' => $actor?->id] + $extra);
        $outstanding = round((float) ExpenseAdvanceEntry::query()->withoutGlobalScope(AccessScope::class)->where('expense_advance_id', $advance->id)->sum('amount'), 2);
        $advance->withAuditReason($note)->update(['outstanding' => max(0, $outstanding)]);

        return $entry;
    }

    private function closeIfSettled(ExpenseAdvance $advance): void
    {
        if ($advance->status === 'disbursed' && (float) $advance->outstanding <= 0) {
            $advance->withAuditReason('Fully settled')->update(['status' => 'settled', 'closed_at' => now()]);
            $this->tell('expense.advance_settled', $advance, []);
        }
    }

    /** @return array{0: ExpenseAdvance, 1: User} */
    private function decidable(ExpenseAdvance $advance, ?User $actor, string $status, string $ability): array
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new ExpenseRuleViolation('An advance is decided by a person.');
        }
        $locked = $this->lock($advance);
        if ($locked->status !== $status) {
            throw new ExpenseRuleViolation('This advance is '.strtolower(ExpenseAdvance::STATUSES[$locked->status] ?? $locked->status).' and cannot be changed that way now.');
        }
        if (! app(ExpensePolicy::class)->{$ability}($actor, $locked)) {
            $own = (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id') === (int) $actor->id;

            throw new ExpenseRuleViolation($own ? 'You cannot decide or pay your own advance.' : 'You cannot act on this advance.');
        }

        return [$locked, $actor];
    }

    /** EX-E: refuses when a payroll run not yet finalized or an unpaid final settlement already pays out or recovers the advance. */
    private function assertFree(ExpenseAdvance $advance, string $key, string $what): void
    {
        if (($where = $this->commitments->of($key, (int) $advance->employee_id, (int) $advance->id)) !== null) {
            throw new ExpenseRuleViolation("{$advance->number} is already ".($key === PayrollCommitments::PAYOUT ? 'being paid out' : 'being recovered')." in {$where}: it cannot be {$what} now.");
        }
    }

    private function lock(ExpenseAdvance $advance): ExpenseAdvance
    {
        return ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->whereKey($advance->getKey())->lockForUpdate()->firstOrFail();
    }

    private function authorise(?User $actor, Employee $employee): User
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new ExpenseRuleViolation('Advances are requested by a person.');
        }
        $own = $employee->user_id !== null && (int) $employee->user_id === (int) $actor->id;
        if ($own ? ! $actor->hasPermission('expense.claim') : ! app(ExpensePolicy::class)->onBehalf($actor, $employee)) {
            throw new ExpenseRuleViolation('You cannot request an advance for this employee.');
        }

        return $actor;
    }

    /** @return list<string> */
    private function files(array $paths): array
    {
        $prefix = 'tenants/'.app(TenantContext::class)->id().'/expense/';
        $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
        $out = [];
        foreach (array_filter($paths, 'is_string') as $path) {
            if (! str_starts_with($path, $prefix) || str_contains($path, '..') || ! $disk->exists($path)) {
                throw new ExpenseRuleViolation('Attach the documents again: one of them could not be found.');
            }
            $out[] = $path;
        }

        return $out;
    }

    private function tell(string $event, ExpenseAdvance $advance, array $context): void
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($advance->employee_id);
        ExpenseEvent::dispatch($event, $employee, $advance, ['number' => $advance->number] + $context, array_filter([$employee->user_id]));
    }

    /** @return list<int> the employee's manager's sign-in, for the in-app note when no rule is set up */
    private function managerUsers(Employee $employee): array
    {
        $managerUser = $employee->currentManager()->with('manager')->first()?->manager?->user_id;

        return array_values(array_filter([$managerUser]));
    }

    private function reference(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 120) {
            throw new ExpenseRuleViolation('Give the payment reference (at most 120 characters).');
        }

        return $reference;
    }

    private function paymentDate(CarbonInterface|string $on): CarbonImmutable
    {
        $on = CarbonImmutable::parse($on)->startOfDay();
        if ($on->gt(CarbonImmutable::today())) {
            throw new ExpenseRuleViolation('A payment is recorded on or before today.');
        }

        return $on;
    }

    private function assertNote(string $note, string $what): void
    {
        if (trim($note) === '') {
            throw new ExpenseRuleViolation("Give the reason for {$what}.");
        }
    }

    /** Notes are kept to their column (500 characters); the audit trail keeps the full text. */
    private static function short(?string $note): ?string
    {
        return $note === null ? null : mb_substr(trim($note), 0, 500);
    }
}
