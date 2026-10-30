<?php

namespace App\Domain\Claims\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Models\ReimbursementLedgerEntry;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reimbursements paid on claim (HROne parity PY-02). A reimbursement component marked pay_on_claim is not paid each
 * month: payroll records the month's amount, and finalization posts it to the employee's entitlement ledger. Claims
 * with bills are checked against the balance (accrued and opening, less paid and claims waiting), approved (possibly
 * for less), paid non-taxable by the next payroll (ReimbursementClaimInput), and posted to the ledger as paid.
 */
final class ReimbursementClaims
{
    public function __construct(private readonly SettingsRepository $settings, private readonly AuditRecorder $audit) {}

    public function financialYear(Carbon|string $date): int
    {
        return app(FinancialYear::class)->startYear(Carbon::parse($date));
    }

    /** Available to claim: ledger balance less claims pending or approved and not yet paid. */
    public function balance(Employee $employee, SalaryComponent $component, int $financialYear, ?int $exceptClaim = null): float
    {
        $ledger = (float) ReimbursementLedgerEntry::query()->where('employee_id', $employee->id)->where('salary_component_id', $component->id)->where('financial_year', $financialYear)->sum('amount');
        $waiting = (float) ReimbursementClaim::query()->where('employee_id', $employee->id)->where('salary_component_id', $component->id)->where('financial_year', $financialYear)
            ->whereIn('status', ['pending', 'approved'])->when($exceptClaim, fn ($q) => $q->whereKeyNot($exceptClaim))->get()->sum(fn (ReimbursementClaim $c) => $c->payable());

        return round($ledger - $waiting, 2);
    }

    /**
     * @param  list<array{number?: string, date?: string, amount?: float|string, vendor?: string}>  $bills
     * @param  list<string>  $attachments
     */
    public function claim(Employee $employee, SalaryComponent $component, float $amount, Carbon|string $claimDate, string $description, array $bills = [], array $attachments = [], ?User $requester = null): ReimbursementClaim
    {
        // HROne setup parity P4: the employee request lock refuses the employee's own claim while it applies.
        app(SelfServiceLock::class)->assertOpen($employee, 'reimbursement_claim', $requester);
        if ($component->type !== 'reimbursement' || ! $component->pay_on_claim) {
            throw new RuntimeException("{$component->name} is not paid on claim.");
        }
        if ($amount <= 0) {
            throw new RuntimeException('The claim must be more than zero.');
        }
        if (trim($description) === '') {
            throw new RuntimeException('Describe what the claim is for.');
        }
        $bills = array_values(array_filter($bills, fn ($b) => is_array($b) && (float) ($b['amount'] ?? 0) > 0));
        $billed = round(array_sum(array_map(fn ($b) => (float) $b['amount'], $bills)), 2);
        // HROne setup parity BP-9 (payroll.claims.not_above_bills): the bills are listed and the claim is at most their total.
        if ((bool) $this->settings->get('payroll.claims.not_above_bills', false)) {
            if ($bills === []) {
                throw new RuntimeException('List the bills with their amounts.');
            }
            if ($amount > $billed + 0.001) {
                throw new RuntimeException('The claim cannot be more than its bills ('.number_format($billed, 2).').');
            }
        } elseif ($bills !== [] && abs($billed - $amount) > 0.01) {
            throw new RuntimeException('The bills must add up to the amount claimed.');
        }
        // HROne setup parity (PS-2): bills may be refused from employees who chose the new regime (reimbursements are taxable there).
        if ($this->settings->get('payroll.claims.new_regime_bills', 'allow') === 'block'
            && EmployeeTaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year', app(FinancialYear::class)->label(Carbon::parse($claimDate)))->value('regime') === 'new') {
            throw new RuntimeException('Bills are not taken under the new tax regime.');
        }
        // HROne setup parity (PS-1): the component may override the organisation's attachment rule, and limit the claim.
        if ((bool) ($component->claim_attachment_required ?? $this->settings->get('payroll.claims.attachment_required', true)) && $attachments === []) {
            throw new RuntimeException('Attach the bills.');
        }
        if ($component->claim_limit_per_claim !== null && (float) $component->claim_limit_per_claim > 0 && $amount > (float) $component->claim_limit_per_claim) {
            throw new RuntimeException("{$component->name} can be claimed up to ".number_format((float) $component->claim_limit_per_claim, 2).' at a time.');
        }
        if ($component->claim_window_days && Carbon::parse($claimDate)->lt(now()->startOfDay()->subDays($component->claim_window_days))) {
            throw new RuntimeException("Bills for {$component->name} must be from the last {$component->claim_window_days} day(s).");
        }
        $fy = $this->financialYear($claimDate);

        return DB::transaction(function () use ($employee, $component, $amount, $claimDate, $description, $bills, $attachments, $requester, $fy) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->value('id');
            // HROne setup parity BP-9 (payroll.claims.one_open_per_component): one claim at a time per reimbursement.
            if ((bool) $this->settings->get('payroll.claims.one_open_per_component', false)
                && ReimbursementClaim::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('salary_component_id', $component->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                throw new RuntimeException("A claim for {$component->name} is still open; claim again once it is paid, rejected or cancelled.");
            }
            $available = $this->balance($employee, $component, $fy);
            if (! (bool) $this->settings->get('payroll.claims.allow_beyond_balance', false) && $amount > $available + 0.001) {
                throw new RuntimeException('You can claim up to '.number_format(max(0, $available), 2)." of {$component->name} now.");
            }
            $claim = new ReimbursementClaim(['employee_id' => $employee->id, 'salary_component_id' => $component->id, 'financial_year' => $fy, 'claim_date' => Carbon::parse($claimDate)->toDateString(),
                'amount' => round($amount, 2), 'description' => $description, 'bills' => $bills ?: null, 'attachments' => $attachments ?: null, 'status' => 'pending', 'requested_by' => ($requester ?? auth()->user())?->id]);
            $claim->withAuditReason($description)->save();
            $this->audit->record(AuditAction::Submitted, 'payroll', $claim, reason: $description, metadata: ['component' => $component->code]);
            $this->event('payroll.claim_submitted', $claim);

            return $claim;
        });
    }

    public function approve(ReimbursementClaim $claim, ?float $approvedAmount = null, ?string $note = null, ?User $actor = null): ReimbursementClaim
    {
        return DB::transaction(function () use ($claim, $approvedAmount, $note, $actor) {
            $locked = $this->decidable($claim, $actor ??= auth()->user());
            $approved = round($approvedAmount ?? (float) $locked->amount, 2);
            if ($approved <= 0 || $approved > (float) $locked->amount) {
                throw new RuntimeException('Approve between 0.01 and the amount claimed.');
            }
            $locked->withAuditReason($note)->update(['status' => 'approved', 'approved_amount' => $approved, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit->record(AuditAction::Approved, 'payroll', $locked, reason: $note, actor: $actor, metadata: ['approved' => $approved]);
            $this->event('payroll.claim_approved', $locked);

            return $locked;
        });
    }

    public function reject(ReimbursementClaim $claim, string $note, ?User $actor = null): ReimbursementClaim
    {
        if (trim($note) === '') {
            throw new RuntimeException('Give the reason for rejecting.');
        }

        return DB::transaction(function () use ($claim, $note, $actor) {
            $locked = $this->decidable($claim, $actor ??= auth()->user());
            $locked->withAuditReason($note)->update(['status' => 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit->record(AuditAction::Rejected, 'payroll', $locked, reason: $note, actor: $actor);
            $this->event('payroll.claim_rejected', $locked);

            return $locked;
        });
    }

    public function cancel(ReimbursementClaim $claim, string $reason): ReimbursementClaim
    {
        if (! in_array($claim->status, ['pending', 'approved'], true)) {
            throw new RuntimeException('Only a claim not yet paid can be cancelled.');
        }
        $claim->withAuditReason($reason)->update(['status' => 'cancelled']);
        $this->audit->record(AuditAction::Cancelled, 'payroll', $claim, reason: $reason);

        return $claim;
    }

    /**
     * HROne setup parity BP-9 (payroll.claims.reminder_task): whether today falls in a reminder month — every month, the
     * last month of each financial-year quarter, or the last month of the year — and that period's key (the command
     * sends once per period).
     *
     * @return string|null the period key (Y-m), or null when there is no reminder this month
     */
    public function reminderPeriod(Carbon $today): ?string
    {
        $index = app(FinancialYear::class)->monthIndex($today);

        return match ((string) $this->settings->get('payroll.claims.reminder_task', 'off')) {
            'monthly' => $today->format('Y-m'),
            'quarter_end' => $index % 3 === 0 ? $today->format('Y-m') : null,
            'year_end' => $index === 12 ? $today->format('Y-m') : null,
            default => null,
        };
    }

    /**
     * Remind every employee with something left to claim this financial year (pay-on-claim balance above zero, after
     * claims waiting): `payroll.claim_reminder` for tenant rules, with an in-app note when no rule is set up.
     *
     * @return int employees reminded
     */
    public function remind(Carbon $today): int
    {
        $fy = $this->financialYear($today);
        $components = SalaryComponent::query()->where('type', 'reimbursement')->where('pay_on_claim', true)->pluck('name', 'id');
        if ($components->isEmpty()) {
            return 0;
        }
        $ledger = ReimbursementLedgerEntry::query()->withoutGlobalScope(AccessScope::class)->where('financial_year', $fy)->whereIn('salary_component_id', $components->keys())
            ->groupBy('employee_id', 'salary_component_id')->selectRaw('employee_id, salary_component_id, SUM(amount) as total')->toBase()->get();
        $waiting = ReimbursementClaim::query()->withoutGlobalScope(AccessScope::class)->where('financial_year', $fy)->whereIn('status', ['pending', 'approved'])->get()
            ->groupBy(fn (ReimbursementClaim $c) => $c->employee_id.':'.$c->salary_component_id)->map(fn ($claims) => $claims->sum(fn (ReimbursementClaim $c) => $c->payable()));
        $balances = [];
        foreach ($ledger as $row) {
            $left = round((float) $row->total - (float) ($waiting[$row->employee_id.':'.$row->salary_component_id] ?? 0), 2);
            if ($left > 0) {
                $balances[(int) $row->employee_id][$components[(int) $row->salary_component_id]] = $left;
            }
        }
        $sent = 0;
        Employee::query()->withoutGlobalScope(AccessScope::class)->employed()->whereIn('id', array_keys($balances))->orderBy('id')->each(function (Employee $employee) use ($balances, &$sent) {
            PayrollEvent::dispatch('payroll.claim_reminder', $employee, ['employee_id' => $employee->id, 'balances' => $balances[$employee->id], 'total' => round(array_sum($balances[$employee->id]), 2),
                'recipients' => array_values(array_filter([$employee->user_id ? (int) $employee->user_id : null]))]);
            $sent++;
        });

        return $sent;
    }

    /** Opening entitlement (go-live in the middle of a year), once per employee, component and year. */
    public function opening(Employee $employee, SalaryComponent $component, int $financialYear, float $amount, string $note): ?ReimbursementLedgerEntry
    {
        return $this->post($employee->id, $component->id, $financialYear, null, 'opening', $amount, "opening-{$financialYear}", $note);
    }

    /** payroll.finalized: post each entry's pay-on-claim amounts to the entitlement ledger. */
    public function onFinalized(PayrollEvent $event): void
    {
        if ($event->name !== 'payroll.finalized' || ! $event->subject instanceof PayrollRun) {
            return;
        }
        $run = $event->subject->loadMissing('period');
        $month = sprintf('%04d-%02d', $run->period->year, $run->period->month);
        $fy = $this->financialYear($run->period->end_date);
        foreach ($run->entries()->get(['id', 'employee_id', 'inputs']) as $entry) {
            foreach ((array) ($entry->inputs['claim_accruals'] ?? []) as $componentId => $amount) {
                if ((float) $amount > 0) {
                    $this->post((int) $entry->employee_id, (int) $componentId, $fy, $month, 'accrual', (float) $amount, "accrual-{$entry->id}", 'Accrued in payroll '.$run->period->label());
                }
            }
        }
    }

    /** The run paid these claims. */
    public function paid(array $claimIds, PayrollRun $run): void
    {
        ReimbursementClaim::query()->whereIn('id', $claimIds)->where('status', 'approved')->get()->each(function (ReimbursementClaim $claim) use ($run) {
            $claim->withAuditReason('Paid in payroll '.$run->period?->label())->update(['status' => 'paid', 'payroll_run_id' => $run->id]);
            $this->post($claim->employee_id, $claim->salary_component_id, $claim->financial_year, sprintf('%04d-%02d', $run->period->year, $run->period->month), 'paid', -$claim->payable(), "claim-{$claim->id}", 'Claim of '.$claim->claim_date->format('d M Y'));
            $this->event('payroll.claim_paid', $claim);
        });
    }

    private function post(int $employeeId, int $componentId, int $fy, ?string $month, string $kind, float $amount, string $key, string $note): ?ReimbursementLedgerEntry
    {
        if (ReimbursementLedgerEntry::query()->where('employee_id', $employeeId)->where('salary_component_id', $componentId)->where('entry_key', $key)->exists()) {
            return null;
        }

        return ReimbursementLedgerEntry::create(['employee_id' => $employeeId, 'salary_component_id' => $componentId, 'financial_year' => $fy, 'month' => $month, 'kind' => $kind, 'amount' => round($amount, 2), 'entry_key' => $key, 'note' => $note, 'created_by' => auth()->id()]);
    }

    /** HROne setup parity P5: claim.* as payroll events — references only (the claim, its component and the employee). */
    private function event(string $name, ReimbursementClaim $claim): void
    {
        PayrollEvent::dispatch($name, $claim, ['claim_id' => (int) $claim->id, 'component_id' => (int) $claim->salary_component_id, 'status' => $claim->status, 'employee_id' => (int) $claim->employee_id]);
    }

    private function decidable(ReimbursementClaim $claim, ?User $actor): ReimbursementClaim
    {
        if ($actor === null) {
            throw new RuntimeException('A claim is decided by a person.');
        }
        $locked = ReimbursementClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'pending') {
            throw new RuntimeException('This claim has already been decided.');
        }
        if (Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id') === $actor->id) {
            throw new RuntimeException('You cannot decide your own claim.');
        }

        return $locked;
    }
}
