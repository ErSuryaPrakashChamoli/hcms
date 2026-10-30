<?php

namespace App\Domain\Loans\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Models\LoanGuarantor;
use App\Domain\Loans\Models\LoanInstallment;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Support\Numbering\NumberSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Employee loans and salary advances (HROne parity PY-01). A request is checked against the employee's `loan`
 * policy; approval builds the instalment schedule (equal instalments, or reducing-balance EMI when the policy sets
 * interest). A loan paid through payroll is disbursed by the next run and becomes active; one paid by bank is
 * marked disbursed at once. Instalments are recovered by payroll in their month (LoanRecoveryInput), prepaid,
 * deferred to the end of the schedule, or settled at exit.
 */
final class Loans
{
    public function __construct(private readonly PolicyResolver $policies, private readonly AuditRecorder $audit) {}

    /**
     * @param  list<Employee>  $guarantors  further guarantors (HROne setup parity BP-9); $guarantor stays the first one
     */
    public function request(Employee $employee, string $type, float $principal, int $installments, string $reason, ?User $requester = null, ?Employee $guarantor = null, ?string $firstDueMonth = null, array $guarantors = []): Loan
    {
        $requester ??= auth()->user();
        // HROne setup parity P4: the employee request lock (overdue tasks / lock period) refuses the employee's own request.
        app(SelfServiceLock::class)->assertOpen($employee, 'loan', $requester);
        $policy = $this->policies->resolve('loan', $employee, now());
        $types = (array) config('peopleos.loans.types', []);
        if (! array_key_exists($type, $types)) {
            throw new RuntimeException("Unknown loan type [{$type}].");
        }
        $allowed = array_values(array_filter((array) ($policy?->setting('allowed_types') ?? [])));
        if ($allowed !== [] && ! in_array($type, $allowed, true)) {
            throw new RuntimeException("{$types[$type]} is not offered under your loan policy.");
        }
        if ($policy !== null && $policy->setting('enabled', true) === false) {
            throw new RuntimeException('Loans are not offered under your loan policy.');
        }
        // HROne setup parity BP-9: a request beyond the policy's limits is refused, or (allow_exception) goes to the approver flagged.
        $exceptions = [];
        $limit = function (string $key, string $message) use ($policy, &$exceptions): void {
            if (! self::yes($policy?->setting('allow_exception'))) {
                throw new RuntimeException($message);
            }
            $exceptions[] = ['limit' => $key, 'message' => $message];
        };
        $max = (float) ($policy?->setting('max_amount') ?? 0);
        if ($principal <= 0) {
            throw new RuntimeException($max > 0 ? 'The amount must be between 1 and '.number_format($max, 2).'.' : 'The amount must be more than zero.');
        }
        if ($max > 0 && $principal > $max) {
            $limit('max_amount', 'The amount must be between 1 and '.number_format($max, 2).'.');
        }
        $maxInstallments = (int) ($policy?->setting('max_installments') ?? 24);
        if ($installments < 1) {
            throw new RuntimeException("Repay in 1 to {$maxInstallments} instalment(s).");
        }
        if ($maxInstallments > 0 && $installments > $maxInstallments) {
            $limit('max_installments', "Repay in 1 to {$maxInstallments} instalment(s).");
        }
        $service = (int) ($policy?->setting('min_service_months') ?? 0);
        if ($service > 0 && ($employee->joining_date === null || $employee->joining_date->copy()->addMonths($service)->gt(now()))) {
            throw new RuntimeException("Loans are available after {$service} month(s) of service.");
        }
        $named = collect([$guarantor, ...$guarantors])->filter()->unique(fn (Employee $g) => $g->getKey())->values();
        if ((bool) ($policy?->setting('guarantor_required') ?? false)) {
            $needed = max(1, (int) ($policy?->setting('guarantors_required') ?? 1));
            if ($named->count() < $needed || $named->contains(fn (Employee $g) => $g->is($employee))) {
                throw new RuntimeException($needed === 1 ? 'Name a colleague as guarantor.' : "Name {$needed} colleagues as guarantors.");
            }
            $this->assertGuarantorsFree($named, (int) ($policy?->setting('max_guarantees_per_guarantor') ?? 0));
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for the loan.');
        }
        $firstDueMonth ??= now()->addMonthNoOverflow()->format('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $firstDueMonth) || $firstDueMonth < now()->format('Y-m')) {
            throw new RuntimeException('Recovery must start this month or later.');
        }
        if (self::yes($policy?->setting('request_window')) && ($window = $this->outsideWindow((int) ($policy?->setting('request_day_from') ?? 0), (int) ($policy?->setting('request_day_to') ?? 0))) !== null) {
            $limit('request_window', $window);
        }

        $company = NumberSeries::companyOf($employee);
        app(NumberSeries::class)->prepare('loan', $company);

        return DB::transaction(function () use ($employee, $type, $principal, $installments, $reason, $requester, $named, $firstDueMonth, $policy, $company, $limit, &$exceptions) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->getKey())->lockForUpdate()->value('id');
            $maxActive = (int) ($policy?->setting('max_active') ?? 1);
            $open = Loan::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved', 'active'])->count();
            if ($maxActive > 0 && $open >= $maxActive) {
                throw new RuntimeException("You already have {$open} open loan(s); the policy allows {$maxActive}.");
            }
            $perYear = (int) ($policy?->setting('max_requests_per_year') ?? 0);
            if ($perYear > 0) {
                $fy = app(FinancialYear::class);
                $asked = Loan::query()->where('employee_id', $employee->id)->where('status', '!=', 'cancelled')
                    ->whereBetween('created_at', [$fy->start(now())->startOfDay(), $fy->end(now())->endOfDay()])->count();
                if ($asked >= $perYear) {
                    $limit('max_requests_per_year', "You can ask for {$perYear} loan(s) a financial year; you have asked for {$asked}.");
                }
            }

            $loan = new Loan([
                'employee_id' => $employee->id, 'number' => app(NumberSeries::class)->next('loan', $company), 'loan_type' => $type, 'principal' => round($principal, 2),
                'interest_rate' => (float) ($policy?->setting('interest_rate') ?? 0), 'installments' => $installments, 'first_due_month' => $firstDueMonth,
                'disbursed_through' => (string) ($policy?->setting('disbursement') ?? 'payroll'), 'status' => 'pending', 'reason' => $reason,
                'guarantor_employee_id' => $named->first()?->id, 'requested_by' => $requester?->id,
            ]);
            $loan->emi = $this->plan($loan)[0]['amount'] ?? 0;
            if (($emiProblem = $this->emiProblem($employee, (float) $loan->emi, (float) ($policy?->setting('max_emi_percent_of_net') ?? 0))) !== null) {
                $limit('max_emi', $emiProblem);
            }
            if ($exceptions !== []) {
                $loan->exceptions = $exceptions;
            }
            $loan->withAuditReason($reason)->save();
            $this->audit->record(AuditAction::Submitted, 'payroll', $loan, reason: $reason, metadata: ['type' => $type, 'installments' => $installments] + ($exceptions !== [] ? ['exceptions' => array_column($exceptions, 'limit')] : []));
            if ($named->count() > 1 || ($named->isNotEmpty() && self::yes($policy?->setting('guarantor_must_accept')))) {
                foreach ($named as $g) {
                    LoanGuarantor::create(['loan_id' => $loan->id, 'employee_id' => $g->id, 'status' => self::yes($policy?->setting('guarantor_must_accept')) ? 'pending' : 'accepted']);
                }
                if (self::yes($policy?->setting('guarantor_must_accept'))) {
                    PayrollEvent::dispatch('payroll.loan_guarantor_requested', $loan, ['number' => $loan->number, 'employee_id' => $employee->id, 'employee' => $employee->person?->full_name,
                        'recipients' => Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $named->pluck('id'))->whereNotNull('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all()]);
                }
            }
            // HROne setup parity P5: the request is an event (notification rules, and its approval workflow when one is set up).
            $this->event('payroll.loan_requested', $loan);

            return $loan;
        });
    }

    /**
     * Approve a pending loan. HROne setup parity BP-9: when the loan policy lets the approver edit the loan
     * (`approver_may_edit`), $changes may set principal, installments and first_due_month within the policy; when it lets
     * them edit the interest (`interest_editable`), interest_rate. The schedule is planned on the approved terms and the
     * request as asked is kept in `requested_terms`. When guarantors must accept, every one must have.
     *
     * @param  array{principal?: float|int|string|null, installments?: int|string|null, first_due_month?: ?string, interest_rate?: float|int|string|null}  $changes
     */
    public function approve(Loan $loan, ?string $note = null, ?User $actor = null, array $changes = []): Loan
    {
        $actor ??= auth()->user();

        return DB::transaction(function () use ($loan, $note, $actor, $changes) {
            $locked = $this->decidable($loan, $actor);
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($locked->employee_id);
            $policy = $this->policies->resolve('loan', $employee, now());
            if (self::yes($policy?->setting('guarantor_must_accept'))) {
                $waiting = $locked->guarantors()->withoutGlobalScope(AccessScope::class)->with('employee.person')->where('status', '!=', 'accepted')->get();
                if ($waiting->isNotEmpty()) {
                    throw new RuntimeException('Every guarantor must accept first; waiting for '.$waiting->map(fn (LoanGuarantor $g) => $g->employee?->person?->full_name ?? 'a guarantor')->implode(', ').'.');
                }
            }
            $terms = $this->editedTerms($locked, $changes, $policy);
            if ($terms !== []) {
                $asked = $locked->only(['principal', 'installments', 'first_due_month', 'interest_rate']);
                $locked->fill($terms + ['requested_terms' => ['principal' => (float) $asked['principal'], 'installments' => (int) $asked['installments'], 'first_due_month' => $asked['first_due_month'], 'interest_rate' => (float) $asked['interest_rate']]]);
                $locked->emi = $this->plan($locked)[0]['amount'] ?? 0;
            }
            $locked->withAuditReason($note)->fill(['status' => 'approved', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->buildSchedule($locked);
            $this->audit->record(AuditAction::Approved, 'payroll', $locked, reason: $note, actor: $actor, metadata: ['emi' => (float) $locked->emi] + ($terms !== [] ? ['edited' => array_keys($terms)] : []));
            $this->event('payroll.loan_approved', $locked);
            if ($locked->disbursed_through === 'bank') {
                $this->markDisbursed($locked, now()->toDateString(), 'Paid by bank on approval');
            }

            return $locked->refresh();
        });
    }

    /** HROne setup parity BP-9: the guarantor accepts or declines standing guarantee (only they can, while the loan is pending). */
    public function decideGuarantee(LoanGuarantor $guarantee, bool $accept, ?string $note, User $actor): LoanGuarantor
    {
        return DB::transaction(function () use ($guarantee, $accept, $note, $actor) {
            $locked = LoanGuarantor::query()->withoutGlobalScope(AccessScope::class)->whereKey($guarantee->getKey())->lockForUpdate()->firstOrFail();
            if (Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id') !== $actor->id) {
                throw new RuntimeException('Only the guarantor can answer for themselves.');
            }
            if ($locked->status !== 'pending' || Loan::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->loan_id)->value('status') !== 'pending') {
                throw new RuntimeException('This guarantee has already been answered, or the loan decided.');
            }
            if (! $accept && trim((string) $note) === '') {
                throw new RuntimeException('Give the reason for declining.');
            }
            $locked->withAuditReason($note)->update(['status' => $accept ? 'accepted' : 'declined', 'decided_at' => now(), 'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null]);

            return $locked;
        });
    }

    public function reject(Loan $loan, string $note, ?User $actor = null): Loan
    {
        if (trim($note) === '') {
            throw new RuntimeException('Give the reason for rejecting.');
        }
        $actor ??= auth()->user();

        return DB::transaction(function () use ($loan, $note, $actor) {
            $locked = $this->decidable($loan, $actor);
            $locked->withAuditReason($note)->update(['status' => 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit->record(AuditAction::Rejected, 'payroll', $locked, reason: $note, actor: $actor);
            $this->event('payroll.loan_rejected', $locked);

            return $locked;
        });
    }

    /** Withdraw a pending loan, or cancel an approved one that has not been paid out. */
    public function cancel(Loan $loan, string $reason): Loan
    {
        return DB::transaction(function () use ($loan, $reason) {
            $locked = Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['pending', 'approved'], true)) {
                throw new RuntimeException('Only a loan not yet paid out can be cancelled.');
            }
            $locked->schedule()->delete();
            $locked->withAuditReason($reason)->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'outstanding' => 0]);
            $this->audit->record(AuditAction::Cancelled, 'payroll', $locked, reason: $reason);

            return $locked;
        });
    }

    public function markDisbursed(Loan $loan, string $on, string $note): Loan
    {
        if ($loan->status !== 'approved') {
            throw new RuntimeException('Only an approved loan can be paid out.');
        }
        $loan->withAuditReason($note)->update(['status' => 'active', 'disbursed_on' => $on, 'outstanding' => $this->outstanding($loan)]);
        $this->event('payroll.loan_disbursed', $loan);

        return $loan;
    }

    /** Recover the next $count due instalments outside payroll (cash or bank). Closes the loan when nothing is left. */
    public function prepay(Loan $loan, int $count, string $note): Loan
    {
        if ($loan->status !== 'active' || $count < 1) {
            throw new RuntimeException('Prepay at least one instalment of an active loan.');
        }

        return DB::transaction(function () use ($loan, $count, $note) {
            $loan->schedule()->where('status', 'due')->orderBy('sequence')->limit($count)->get()
                ->each(fn (LoanInstallment $i) => $i->withAuditReason($note)->update(['status' => 'prepaid', 'recovered_on' => now()->toDateString(), 'note' => $note]));

            return $this->refreshOutstanding($loan);
        });
    }

    /** Defer one due instalment to the end of the schedule (a one-instalment tenure extension). */
    public function defer(LoanInstallment $installment, string $note): LoanInstallment
    {
        if ($installment->status !== 'due') {
            throw new RuntimeException('Only a due instalment can be deferred.');
        }

        return DB::transaction(function () use ($installment, $note) {
            $loan = Loan::query()->whereKey($installment->loan_id)->lockForUpdate()->firstOrFail();
            $last = $loan->schedule()->reorder('sequence', 'desc')->first();
            $installment->withAuditReason($note)->update(['status' => 'deferred', 'note' => $note]);
            $moved = LoanInstallment::create([
                'loan_id' => $loan->id, 'employee_id' => $loan->employee_id, 'sequence' => $last->sequence + 1,
                'due_month' => Carbon::parse($last->due_month.'-01')->addMonthNoOverflow()->format('Y-m'),
                'principal_part' => $installment->principal_part, 'interest_part' => $installment->interest_part, 'amount' => $installment->amount, 'note' => 'Deferred from '.$installment->due_month,
            ]);
            $loan->withAuditReason($note)->update(['installments' => $loan->installments + 1]);

            return $moved;
        });
    }

    /** Payroll recovered these instalments in the run. */
    public function recovered(array $installmentIds, int $runId, string $on): void
    {
        LoanInstallment::query()->whereIn('id', $installmentIds)->where('status', 'due')->get()
            ->each(fn (LoanInstallment $i) => $i->withAuditReason('Recovered in payroll')->update(['status' => 'recovered', 'payroll_run_id' => $runId, 'recovered_on' => $on]));
        Loan::query()->whereIn('id', LoanInstallment::query()->whereIn('id', $installmentIds)->select('loan_id'))->get()->each(fn (Loan $l) => $this->refreshOutstanding($l));
    }

    /** Settled in full and final settlement: every due instalment is recovered there. */
    public function settledAtExit(Loan $loan, string $note): Loan
    {
        $loan->schedule()->where('status', 'due')->get()->each(fn (LoanInstallment $i) => $i->withAuditReason($note)->update(['status' => 'recovered', 'recovered_on' => now()->toDateString(), 'note' => $note]));

        return $this->refreshOutstanding($loan);
    }

    /** @return list<array{sequence: int, due_month: string, principal_part: float, interest_part: float, amount: float}> */
    public function plan(Loan $loan): array
    {
        $n = (int) $loan->installments;
        $principal = round((float) $loan->principal, 2);
        $rate = (float) $loan->interest_rate / 1200;
        $emi = $rate > 0 ? round($principal * $rate * (1 + $rate) ** $n / ((1 + $rate) ** $n - 1), 2) : round($principal / $n, 2);
        $month = Carbon::parse($loan->first_due_month.'-01');
        $balance = $principal;
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $interest = round($balance * $rate, 2);
            $principalPart = $i === $n ? $balance : round(min($balance, $emi - $interest), 2);
            $balance = round($balance - $principalPart, 2);
            $rows[] = ['sequence' => $i, 'due_month' => $month->format('Y-m'), 'principal_part' => $principalPart, 'interest_part' => $interest, 'amount' => round($principalPart + $interest, 2)];
            $month->addMonthNoOverflow();
        }

        return $rows;
    }

    private function buildSchedule(Loan $loan): void
    {
        $loan->schedule()->delete();
        foreach ($this->plan($loan) as $row) {
            LoanInstallment::create($row + ['loan_id' => $loan->id, 'employee_id' => $loan->employee_id]);
        }
        $loan->withoutAuditing()->update(['outstanding' => $this->outstanding($loan)]);
    }

    private function refreshOutstanding(Loan $loan): Loan
    {
        $outstanding = $this->outstanding($loan);
        $loan->withAuditReason('Outstanding recalculated')->update(['outstanding' => $outstanding] + ($outstanding <= 0 && $loan->status === 'active' ? ['status' => 'closed', 'closed_at' => now()] : []));

        return $loan;
    }

    private function outstanding(Loan $loan): float
    {
        return round((float) LoanInstallment::query()->where('loan_id', $loan->id)->where('status', 'due')->sum('amount'), 2);
    }

    /** @return array<string, mixed> the terms the approver changed, checked against the policy */
    private function editedTerms(Loan $loan, array $changes, mixed $policy): array
    {
        $terms = [];
        foreach (['principal' => 'float', 'installments' => 'int', 'first_due_month' => 'string', 'interest_rate' => 'float'] as $key => $kind) {
            if (! array_key_exists($key, $changes) || $changes[$key] === null || $changes[$key] === '') {
                continue;
            }
            $value = match ($kind) {
                'float' => round((float) $changes[$key], $key === 'interest_rate' ? 3 : 2), 'int' => (int) $changes[$key], default => (string) $changes[$key],
            };
            $current = match ($kind) {
                'float' => round((float) $loan->{$key}, $key === 'interest_rate' ? 3 : 2), 'int' => (int) $loan->{$key}, default => (string) $loan->{$key},
            };
            if ($value !== $current) {
                $terms[$key] = $value;
            }
        }
        if (array_diff_key($terms, ['interest_rate' => true]) !== [] && ! self::yes($policy?->setting('approver_may_edit'))) {
            throw new RuntimeException('The loan policy does not let the approver change the amount, instalments or recovery month.');
        }
        if (array_key_exists('interest_rate', $terms) && ! self::yes($policy?->setting('interest_editable'))) {
            throw new RuntimeException('The loan policy does not let the approver change the interest rate.');
        }
        $max = (float) ($policy?->setting('max_amount') ?? 0);
        if (isset($terms['principal']) && ($terms['principal'] <= 0 || ($max > 0 && $terms['principal'] > $max))) {
            throw new RuntimeException($max > 0 ? 'Approve an amount between 1 and '.number_format($max, 2).'.' : 'Approve an amount above zero.');
        }
        $maxInstallments = (int) ($policy?->setting('max_installments') ?? 24);
        if (isset($terms['installments']) && ($terms['installments'] < 1 || ($maxInstallments > 0 && $terms['installments'] > $maxInstallments))) {
            throw new RuntimeException("Approve 1 to {$maxInstallments} instalment(s).");
        }
        if (isset($terms['first_due_month']) && (! preg_match('/^\d{4}-\d{2}$/', $terms['first_due_month']) || $terms['first_due_month'] < now()->format('Y-m'))) {
            throw new RuntimeException('Recovery must start this month or later.');
        }
        if (isset($terms['interest_rate']) && ($terms['interest_rate'] < 0 || $terms['interest_rate'] > 100)) {
            throw new RuntimeException('Give an interest rate from 0 to 100% a year.');
        }

        return $terms;
    }

    /** @param  Collection<int, Employee>  $guarantors */
    private function assertGuarantorsFree($guarantors, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }
        foreach ($guarantors as $g) {
            $open = Loan::query()->withoutGlobalScope(AccessScope::class)->whereIn('status', ['pending', 'approved', 'active'])
                ->where(fn ($q) => $q->where('guarantor_employee_id', $g->id)->orWhereIn('id', LoanGuarantor::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $g->id)->where('status', '!=', 'declined')->select('loan_id')))
                ->count();
            if ($open >= $limit) {
                throw new RuntimeException(($g->person?->full_name ?? 'That colleague')." already stands guarantor for {$open} open loan(s); the policy allows {$limit}.");
            }
        }
    }

    /** The request window's message when today is outside it (days of the month; a window may run over the month end). */
    private function outsideWindow(int $from, int $to): ?string
    {
        if ($from < 1 || $to < 1) {
            return null;
        }
        $day = (int) now()->day;
        $inside = $from <= $to ? ($day >= $from && $day <= $to) : ($day >= $from || $day <= $to);

        return $inside ? null : "Loans can be requested from day {$from} to day {$to} of the month.";
    }

    /** What is wrong with the monthly recovery against the policy's share of net pay (null when within it, or off). */
    private function emiProblem(Employee $employee, float $emi, float $percent): ?string
    {
        if ($percent <= 0) {
            return null;
        }
        $base = app(PaidPayReader::class)->latestPaid($employee)['net'] ?? app(CompensationOutput::class)->on($employee)?->monthlyCtc();
        if ($base === null || $base <= 0) {
            return null;
        }
        $open = (float) Loan::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved', 'active'])->sum('emi');
        $total = round($open + $emi, 2);
        $cap = round($base * $percent / 100, 2);

        return $total > $cap ? 'The monthly recovery with your open loans would be '.number_format($total, 2).', above '.rtrim(rtrim(number_format($percent, 2), '0'), '.').'% of your pay ('.number_format($cap, 2).').' : null;
    }

    private static function yes(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    /** HROne setup parity P5: loan.* as payroll events — references only (number, type, the employee and who asked). */
    private function event(string $name, Loan $loan): void
    {
        PayrollEvent::dispatch($name, $loan, ['number' => $loan->number, 'type' => $loan->loan_type, 'status' => $loan->status, 'employee_id' => (int) $loan->employee_id]);
    }

    private function decidable(Loan $loan, ?User $actor): Loan
    {
        if ($actor === null) {
            throw new RuntimeException('A loan is decided by a person.');
        }
        $locked = Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'pending') {
            throw new RuntimeException('This loan has already been decided.');
        }
        if (Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id') === $actor->id) {
            throw new RuntimeException('You cannot decide your own loan.');
        }

        return $locked;
    }
}
