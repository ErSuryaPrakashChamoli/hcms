<?php

namespace App\Domain\Expense\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Expense\Contracts\PayrollMonths;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Models\ExpenseAdvanceRecoverySchedule;
use App\Domain\Expense\Policies\ExpensePolicy;
use App\Domain\Expense\Support\ExpenseCommitments;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HROne parity XO-6 (matrix 26 §26.3.4, §26.3.1 #8): the advance recovery desk's rules.
 *
 * - Recover through payroll (owner decision D-XO2 / EXP-8, its recommended "yes"): finance (`expense.pay`, in scope, never
 *   one's own) schedules an amount of a dispensed advance for a payroll month — an open month of the employee's company not
 *   finalized (`PayrollMonths`, as XO-4) — at most what is outstanding and not yet scheduled. For a whole employee the amount
 *   is spread over their advances, oldest settle-by first. A scheduled recovery takes precedence for its advance: payroll
 *   recovers the advance only in its scheduled months (ExpenseAdvanceRecoveryInput); an advance without one is recovered by
 *   the policy's automatic date exactly as before.
 * - Never twice: a schedule is recovered once, by the finalized run of its month (marked with the ledger entry, in
 *   ExpenseAdvances::recovered); an advance a payroll run not yet finalized (or an unpaid final settlement) carries cannot
 *   be scheduled, moved or cancelled ("it stays with that payroll"); a finalized month is never offered nor accepted; a
 *   month finalized without it leaves the schedule in place, flagged (missed()), until finance moves or cancels it; payroll
 *   never recovers more than is outstanding.
 * - Recovery outside payroll: one refund for an employee, spread over their advances oldest first, through
 *   ExpenseAdvances::refund (mode, bank, cheque and note on each ledger entry; audited).
 * - The employee ledger: every entry of the employee's advances in date order with the running outstanding.
 */
final class AdvanceRecoveries
{
    public function __construct(
        private readonly ExpenseAdvances $advances,
        private readonly ExpenseCommitments $commitments,
    ) {}

    /** Schedules a recovery of this advance in a payroll month ("Y-m"). */
    public function schedule(ExpenseAdvance $advance, string $month, float $amount, ?string $note = null, ?User $actor = null): ExpenseAdvanceRecoverySchedule
    {
        $actor = $this->actor($actor);
        $note = self::note($note);

        return DB::transaction(function () use ($advance, $month, $amount, $note, $actor) {
            $locked = $this->lockAdvance($advance, $actor);
            $this->assertNotCarried($locked, 'scheduled');
            $for = $this->month((int) $locked->employee_id, $locked->number, $month);
            if ($this->openSchedules($locked)->contains(fn (ExpenseAdvanceRecoverySchedule $s) => $s->recover_in->toDateString() === $for)) {
                throw new ExpenseRuleViolation("{$locked->number} already has a recovery in ".self::label($for).': change that recovery instead.');
            }
            $free = $this->unscheduled($locked);
            $amount = round($amount, 2);
            if ($amount <= 0 || $amount > $free + 0.004) {
                throw new ExpenseRuleViolation("Recover between 0.01 and what is outstanding on {$locked->number} and not yet scheduled (".number_format($free, 2).').');
            }

            return $this->create($locked, $for, $amount, $note, $actor);
        });
    }

    /**
     * "Recover through payroll" for an employee: $amount (blank = everything outstanding and not yet scheduled) is spread over
     * their dispensed advances, oldest settle-by first; advances a payroll run is already recovering, or that already have a
     * recovery in that month, are left out.
     *
     * @return Collection<int, ExpenseAdvanceRecoverySchedule>
     */
    public function scheduleForEmployee(Employee $employee, string $month, ?float $amount = null, ?string $note = null, ?User $actor = null): Collection
    {
        $actor = $this->actor($actor);
        $note = self::note($note);

        return DB::transaction(function () use ($employee, $month, $amount, $note, $actor) {
            $advances = $this->lockOutstanding($employee, $actor);
            $for = $this->month((int) $employee->id, self::who($employee), $month);
            $carried = $this->commitments->pending(PayrollCommitments::RECOVERY, (int) $employee->id);
            $free = [];
            foreach ($advances as $advance) {
                $open = $this->openSchedules($advance);
                if (! isset($carried[$advance->id]) && ! $open->contains(fn (ExpenseAdvanceRecoverySchedule $s) => $s->recover_in->toDateString() === $for)) {
                    $free[$advance->id] = round((float) $advance->outstanding - (float) $open->sum(fn (ExpenseAdvanceRecoverySchedule $s) => (float) $s->amount), 2);
                }
            }
            $free = array_filter($free, fn (float $f) => $f > 0);
            $total = round(array_sum($free), 2);
            if ($total <= 0) {
                throw new ExpenseRuleViolation(self::who($employee).': nothing outstanding is left to schedule for '.self::label($for).($carried !== [] ? ' (a payroll being prepared is already recovering '.implode(', ', array_unique($carried)).')' : '').'.');
            }
            $amount = $amount === null ? $total : round($amount, 2);
            if ($amount <= 0 || $amount > $total + 0.004) {
                throw new ExpenseRuleViolation('Recover between 0.01 and what is outstanding and not yet scheduled for '.self::label($for).' ('.number_format($total, 2).').');
            }
            $schedules = collect();
            foreach ($advances as $advance) {
                if ($amount <= 0) {
                    break;
                }
                $take = round(min($amount, $free[$advance->id] ?? 0.0), 2);
                if ($take <= 0) {
                    continue;
                }
                $schedules->push($this->create($advance, $for, $take, $note, $actor));
                $amount = round($amount - $take, 2);
            }

            return $schedules;
        });
    }

    /**
     * Moves an open scheduled recovery to another open month and, optionally, changes its amount. Refused once a payroll run
     * carries the advance: it is recovered by that run when it is finalized, never in a second month.
     */
    public function reschedule(ExpenseAdvanceRecoverySchedule $schedule, string $month, ?float $amount = null, ?string $note = null, ?User $actor = null): ExpenseAdvanceRecoverySchedule
    {
        $actor = $this->actor($actor);
        $note = self::note($note);

        return DB::transaction(function () use ($schedule, $month, $amount, $note, $actor) {
            [$locked, $advance] = $this->lockSchedule($schedule, $actor);
            $for = $this->month((int) $advance->employee_id, $advance->number, $month);
            $amount = $amount === null ? round((float) $locked->amount, 2) : round($amount, 2);
            $before = $locked->monthLabel();
            if ($for === $locked->recover_in->toDateString() && abs($amount - (float) $locked->amount) < 0.005) {
                throw new ExpenseRuleViolation("This recovery of {$advance->number} is already for {$before} (".number_format($amount, 2).').');
            }
            $others = $this->openSchedules($advance)->reject(fn (ExpenseAdvanceRecoverySchedule $s) => (int) $s->id === (int) $locked->id);
            if ($others->contains(fn (ExpenseAdvanceRecoverySchedule $s) => $s->recover_in->toDateString() === $for)) {
                throw new ExpenseRuleViolation("{$advance->number} already has a recovery in ".self::label($for).': change that recovery instead.');
            }
            $free = round((float) $advance->outstanding - (float) $others->sum(fn (ExpenseAdvanceRecoverySchedule $s) => (float) $s->amount), 2);
            if ($amount <= 0 || $amount > $free + 0.004) {
                throw new ExpenseRuleViolation("Recover between 0.01 and what is outstanding on {$advance->number} and not yet scheduled (".number_format(max(0, $free), 2).').');
            }
            $after = self::label($for);
            $change = $before === $after ? "Recovery amount changed ({$after}): ".number_format((float) $locked->amount, 2).' → '.number_format($amount, 2)
                : "Recovery month changed: {$before} → {$after}".(abs($amount - (float) $locked->amount) >= 0.005 ? '; '.number_format((float) $locked->amount, 2).' → '.number_format($amount, 2) : '');
            $locked->withAuditReason($change)->update(['recover_in' => $for, 'amount' => $amount, 'scheduled_by' => $actor->id] + ($note !== null ? ['note' => $note] : []));

            return $locked->refresh();
        });
    }

    /** Cancels an open scheduled recovery (the advance goes back to its automatic recovery, if any). Refused once a payroll run carries it. */
    public function cancel(ExpenseAdvanceRecoverySchedule $schedule, ?string $reason = null, ?User $actor = null): ExpenseAdvanceRecoverySchedule
    {
        $actor = $this->actor($actor);
        $reason = self::note($reason);

        return DB::transaction(function () use ($schedule, $reason, $actor) {
            [$locked] = $this->lockSchedule($schedule, $actor);
            $locked->withAuditReason('Recovery cancelled ('.$locked->monthLabel().')'.($reason !== null ? ': '.$reason : ''))
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason]);

            return $locked->refresh();
        });
    }

    /**
     * Money the employee paid back outside payroll (§26.3.4 #9): $amount (blank = everything outstanding that no payroll run
     * is recovering) is refunded on their advances oldest settle-by first, each through ExpenseAdvances::refund with the same
     * reference and details. All or nothing.
     *
     * @param  array{mode?: ?string, bank_name?: ?string, cheque_number?: ?string, cheque_date?: mixed, note?: ?string}  $details
     * @return array<string, float> advance number => amount refunded
     */
    public function refundForEmployee(Employee $employee, ?float $amount, string $reference, CarbonInterface|string $on, array $details = [], ?User $actor = null): array
    {
        $actor = $this->actor($actor);
        ExpenseAdvances::refundDetails($details);

        return DB::transaction(function () use ($employee, $amount, $reference, $on, $details, $actor) {
            $carried = $this->commitments->pending(PayrollCommitments::RECOVERY, (int) $employee->id);
            $advances = $this->lockOutstanding($employee, $actor)->reject(fn (ExpenseAdvance $a) => isset($carried[$a->id]))->values();
            $total = round((float) $advances->sum(fn (ExpenseAdvance $a) => (float) $a->outstanding), 2);
            if ($total <= 0) {
                throw new ExpenseRuleViolation(self::who($employee).': nothing outstanding can be recovered now'.($carried !== [] ? ' (it is being recovered in '.implode(', ', array_unique($carried)).')' : '').'.');
            }
            $amount = $amount === null ? $total : round($amount, 2);
            if ($amount <= 0 || $amount > $total + 0.004) {
                throw new ExpenseRuleViolation('Recover between 0.01 and what is outstanding ('.number_format($total, 2).').');
            }
            $done = [];
            foreach ($advances as $advance) {
                if ($amount <= 0) {
                    break;
                }
                $take = round(min($amount, (float) $advance->outstanding), 2);
                $this->advances->refund($advance, $take, $reference, $on, $actor, $details);
                $done[$advance->number] = $take;
                $amount = round($amount - $take, 2);
            }

            return $done;
        });
    }

    /** The open recovery's month was finalized without it: it stays scheduled — no other month takes it — until moved or cancelled. */
    public function missed(ExpenseAdvanceRecoverySchedule $schedule): bool
    {
        return $schedule->isOpen() && app(PayrollMonths::class)->isFinalized((int) $schedule->employee_id, $schedule->month());
    }

    /** What the employee still owes on dispensed advances that is not yet scheduled for a payroll month (in the viewer's scope). */
    public function unscheduledFor(Employee $employee): float
    {
        $outstanding = (float) ExpenseAdvance::query()->where('employee_id', $employee->id)->where('status', 'disbursed')->where('outstanding', '>', 0)->sum('outstanding');
        $scheduled = (float) ExpenseAdvanceRecoverySchedule::query()->where('employee_id', $employee->id)->where('status', 'scheduled')
            ->whereHas('advance', fn ($q) => $q->where('status', 'disbursed'))->sum('amount');

        return max(0.0, round($outstanding - $scheduled, 2));
    }

    /** What could be recovered outside payroll right now: outstanding on advances no payroll run (or final settlement) is recovering. */
    public function refundableFor(Employee $employee): float
    {
        $carried = $this->commitments->pending(PayrollCommitments::RECOVERY, (int) $employee->id);

        return round((float) ExpenseAdvance::query()->where('employee_id', $employee->id)->where('status', 'disbursed')->where('outstanding', '>', 0)->get(['id', 'outstanding'])
            ->reject(fn (ExpenseAdvance $a) => isset($carried[$a->id]))->sum(fn (ExpenseAdvance $a) => (float) $a->outstanding), 2);
    }

    /**
     * How "Recover through payroll" would spread an amount (blank = everything not yet scheduled) — for the confirmation step.
     *
     * @return array<string, float> advance number => amount
     */
    public function preview(Employee $employee, ?string $month, ?float $amount): array
    {
        $for = preg_match('/^\d{4}-\d{2}$/', (string) $month) === 1 ? $month.'-01' : null;
        $carried = $this->commitments->pending(PayrollCommitments::RECOVERY, (int) $employee->id);
        $out = [];
        $left = $amount;
        foreach (self::outstandingQuery($employee)->get() as $advance) {
            $open = $this->openSchedules($advance);
            if (isset($carried[$advance->id]) || ($for !== null && $open->contains(fn (ExpenseAdvanceRecoverySchedule $s) => $s->recover_in->toDateString() === $for))) {
                continue;
            }
            $free = round((float) $advance->outstanding - (float) $open->sum(fn (ExpenseAdvanceRecoverySchedule $s) => (float) $s->amount), 2);
            $take = round($left === null ? $free : min($left, $free), 2);
            if ($take > 0) {
                $out[$advance->number] = $take;
                $left = $left === null ? null : round($left - $take, 2);
            }
            if ($left !== null && $left <= 0) {
                break;
            }
        }

        return $out;
    }

    /**
     * The employee's advance ledger (§26.3.1 #8): every entry of their advances in date order with the outstanding before and
     * after it — what HROne's "Advance Outstanding ledger" shows. Read through the viewer's access scope.
     *
     * @return list<array{date: string, advance: string, advance_id: int, kind: string, reason: string, reference: ?string, opening: float, change: float, closing: float}>
     */
    public function ledger(Employee $employee): array
    {
        $balance = 0.0;
        $rows = [];
        ExpenseAdvanceEntry::query()->where('employee_id', $employee->id)->with('advance:id,number')->orderBy('entry_date')->orderBy('id')->get()
            ->each(function (ExpenseAdvanceEntry $entry) use (&$balance, &$rows) {
                $opening = $balance;
                $balance = round($balance + (float) $entry->amount, 2);
                $rows[] = [
                    'date' => $entry->entry_date->toDateString(), 'advance' => (string) $entry->advance?->number, 'advance_id' => (int) $entry->expense_advance_id,
                    'kind' => ExpenseAdvanceEntry::KINDS[$entry->kind] ?? $entry->kind,
                    'reason' => implode(' · ', array_filter([$entry->note, $entry->refundModeLabel()])), 'reference' => $entry->reference,
                    'opening' => round($opening, 2), 'change' => round((float) $entry->amount, 2), 'closing' => $balance,
                ];
            });

        return $rows;
    }

    /** The employee's dispensed advances with something outstanding, oldest settle-by first (the order recoveries are spread). */
    public static function outstandingQuery(Employee $employee)
    {
        return ExpenseAdvance::query()->where('employee_id', $employee->id)->where('status', 'disbursed')->where('outstanding', '>', 0)
            ->orderByRaw('settle_by is null')->orderBy('settle_by')->orderBy('disbursed_on')->orderBy('id');
    }

    // --- Internals -----------------------------------------------------------------------------------------------------

    private function create(ExpenseAdvance $advance, string $for, float $amount, ?string $note, User $actor): ExpenseAdvanceRecoverySchedule
    {
        $schedule = new ExpenseAdvanceRecoverySchedule([
            'expense_advance_id' => $advance->id, 'employee_id' => $advance->employee_id, 'recover_in' => $for, 'amount' => $amount, 'status' => 'scheduled',
            'note' => $note, 'scheduled_by' => $actor->id,
        ]);
        $schedule->withAuditReason("Recovery of {$advance->number} scheduled: ".self::label($for).' payroll, '.number_format($amount, 2))->save();

        return $schedule;
    }

    /** @return Collection<int, ExpenseAdvanceRecoverySchedule> the advance's open scheduled recoveries */
    private function openSchedules(ExpenseAdvance $advance): Collection
    {
        return ExpenseAdvanceRecoverySchedule::query()->withoutGlobalScope(AccessScope::class)->where('expense_advance_id', $advance->id)->where('status', 'scheduled')->orderBy('recover_in')->get();
    }

    private function unscheduled(ExpenseAdvance $advance): float
    {
        return round((float) $advance->outstanding - (float) $this->openSchedules($advance)->sum(fn (ExpenseAdvanceRecoverySchedule $s) => (float) $s->amount), 2);
    }

    /** The advance, locked: dispensed, with something outstanding, the actor may recover it (in scope, never their own). */
    private function lockAdvance(ExpenseAdvance $advance, User $actor): ExpenseAdvance
    {
        $locked = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->whereKey($advance->getKey())->lockForUpdate()->firstOrFail();
        $this->assertMayRecover($locked, $actor);
        if ($locked->status !== 'disbursed' || (float) $locked->outstanding <= 0) {
            throw new ExpenseRuleViolation("Nothing is outstanding on {$locked->number}.");
        }

        return $locked;
    }

    /** @return Collection<int, ExpenseAdvance> the employee's outstanding advances, locked, oldest settle-by first */
    private function lockOutstanding(Employee $employee, User $actor): Collection
    {
        $advances = self::outstandingQuery($employee)->withoutGlobalScope(AccessScope::class)->lockForUpdate()->get();
        if ($advances->isEmpty()) {
            throw new ExpenseRuleViolation(self::who($employee).' has nothing outstanding on expense advances.');
        }
        foreach ($advances as $advance) {
            $this->assertMayRecover($advance, $actor);
        }

        return $advances;
    }

    /** @return array{0: ExpenseAdvanceRecoverySchedule, 1: ExpenseAdvance} the open schedule and its advance, both locked */
    private function lockSchedule(ExpenseAdvanceRecoverySchedule $schedule, User $actor): array
    {
        $advance = ExpenseAdvance::query()->withoutGlobalScope(AccessScope::class)->whereKey($schedule->expense_advance_id)->lockForUpdate()->firstOrFail();
        $locked = ExpenseAdvanceRecoverySchedule::query()->withoutGlobalScope(AccessScope::class)->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
        $this->assertMayRecover($advance, $actor);
        if (! $locked->isOpen()) {
            throw new ExpenseRuleViolation("This recovery of {$advance->number} is ".strtolower(ExpenseAdvanceRecoverySchedule::STATUSES[$locked->status] ?? $locked->status).' and cannot be changed.');
        }
        $this->assertNotCarried($advance, 'moved or cancelled', true);

        return [$locked, $advance];
    }

    private function assertMayRecover(ExpenseAdvance $advance, User $actor): void
    {
        if (! app(ExpensePolicy::class)->pay($actor, $advance)) {
            $own = (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($advance->employee_id)->value('user_id') === (int) $actor->id;

            throw new ExpenseRuleViolation($own ? 'You cannot recover your own advances.' : 'You cannot recover this advance.');
        }
    }

    /** A payroll run not yet finalized (or an unpaid final settlement) recovering the advance keeps it. */
    private function assertNotCarried(ExpenseAdvance $advance, string $what, bool $stays = false): void
    {
        if (($where = $this->commitments->of(PayrollCommitments::RECOVERY, (int) $advance->employee_id, (int) $advance->id)) !== null) {
            throw new ExpenseRuleViolation("{$advance->number} is already being recovered in {$where}: ".($stays ? 'it stays with that payroll.' : "it cannot be {$what} now."));
        }
    }

    /**
     * The payroll month chosen ("Y-m") as its first day: an open month of the employee's company that is not finalized
     * (XO-4's rule, D-XO1). A month is required — the automatic recovery needs no schedule.
     */
    private function month(int $employeeId, string $who, ?string $month): string
    {
        $month = trim((string) $month);
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw new ExpenseRuleViolation('Choose the payroll month that recovers it.');
        }
        $label = CarbonImmutable::create((int) $m[1], (int) $m[2], 1)->format('M Y');
        $months = app(PayrollMonths::class);
        if ($months->isFinalized($employeeId, $month)) {
            throw new ExpenseRuleViolation("The payroll of {$label} is finalized: choose a month not yet finalized.");
        }
        $open = $months->openFor($employeeId);
        if ($open === []) {
            throw new ExpenseRuleViolation("{$who}: the employee has no company to run payroll for; record the recovery outside payroll instead.");
        }
        if (! array_key_exists($month, $open)) {
            $keys = array_keys($open);
            $range = CarbonImmutable::parse(reset($keys).'-01')->format('M Y').' to '.CarbonImmutable::parse(end($keys).'-01')->format('M Y');

            throw new ExpenseRuleViolation("{$label} is not an open payroll month for {$who}: choose from {$range}.");
        }

        return sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
    }

    private function actor(?User $actor): User
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw new ExpenseRuleViolation('An advance is recovered by a person.');
        }

        return $actor;
    }

    private static function note(?string $note): ?string
    {
        $note = trim((string) $note);
        if (mb_strlen($note) > 500) {
            throw new ExpenseRuleViolation('Keep the note to 500 characters.');
        }

        return $note === '' ? null : $note;
    }

    private static function label(string $firstDay): string
    {
        return CarbonImmutable::parse($firstDay)->format('M Y');
    }

    private static function who(Employee $employee): string
    {
        return (string) $employee->employee_code;
    }
}
