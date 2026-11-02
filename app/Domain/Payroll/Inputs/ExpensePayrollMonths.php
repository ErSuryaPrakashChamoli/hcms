<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Contracts\PayrollMonths;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayCycle;
use Carbon\CarbonImmutable;

/**
 * HROne parity XO-4 (owner decision D-XO1): the payroll months an expense report may be paid in, read from the pay cycle
 * (PayCycle) and the company's payroll months — never created or changed here. A month is open until its payroll is
 * finalized (the month closes, or a run of it is finalized or paid). The month holding today is "the next payroll" of the
 * report scheduled without a month; the months after it may be chosen, and an earlier month whose payroll is still being
 * prepared (a run open, not finalized) too. Also the paying bank accounts (BP-13) of the employee's company.
 */
final class ExpensePayrollMonths implements PayrollMonths
{
    /** How many months from the month holding today are offered. */
    public const AHEAD = 12;

    public function __construct(private readonly PayCycle $cycle) {}

    public function openFor(int $employeeId): array
    {
        $companyId = $this->companyOf($employeeId);
        if ($companyId === null) {
            return [];
        }
        [$year, $month] = $this->cycle->monthContaining($companyId, today());
        $first = CarbonImmutable::create($year, $month, 1);
        $periods = PayrollPeriod::query()->where('company_id', $companyId)->with('runs:id,payroll_period_id,status')->get()->keyBy(fn (PayrollPeriod $p) => $p->key());

        $months = [];
        foreach ($periods as $key => $period) {
            if ($key < $first->format('Y-m') && ! self::finalized($period) && $period->runs->contains(fn (PayrollRun $r) => ! $r->isLocked())) {
                $months[$key] = $period->label().' — payroll being prepared';
            }
        }
        for ($i = 0; $i < self::AHEAD; $i++) {
            $at = $first->addMonthsNoOverflow($i);
            $period = $periods[$at->format('Y-m')] ?? null;
            if ($period !== null && self::finalized($period)) {
                continue;
            }
            $months[$at->format('Y-m')] = $at->format('M Y').self::note($period);
        }
        ksort($months);

        return $months;
    }

    public function upcoming(): array
    {
        [$year, $month] = $this->cycle->monthOf(today());
        $first = CarbonImmutable::create($year, $month, 1);
        $months = [];
        for ($i = 0; $i < self::AHEAD; $i++) {
            $at = $first->addMonthsNoOverflow($i);
            $months[$at->format('Y-m')] = $at->format('M Y');
        }

        return $months;
    }

    public function isFinalized(int $employeeId, string $month): bool
    {
        $companyId = $this->companyOf($employeeId);
        if ($companyId === null || preg_match('/^(\d{4})-(\d{2})$/', $month, $m) !== 1) {
            return false;
        }
        $period = PayrollPeriod::query()->where('company_id', $companyId)->where('year', (int) $m[1])->where('month', (int) $m[2])->with('runs:id,payroll_period_id,status')->first();

        return $period !== null && self::finalized($period);
    }

    public function payingAccounts(?int $employeeId = null): array
    {
        $companyId = $employeeId === null ? null : $this->companyOf($employeeId);
        if ($employeeId !== null && $companyId === null) {
            return [];
        }

        return CompanyBankAccount::query()->where('status', 'active')->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderByDesc('is_default')->orderBy('id')->get()
            ->mapWithKeys(fn (CompanyBankAccount $a) => [(int) $a->id => $a->label()])->all();
    }

    private function companyOf(int $employeeId): ?int
    {
        $companyId = Employee::query()->withoutGlobalScope(AccessScope::class)->with('currentPosition')->find($employeeId)?->currentPosition?->company_id;

        return $companyId !== null ? (int) $companyId : null;
    }

    /** Closed, or a run of it finalized or paid: nothing more is added to it. */
    private static function finalized(PayrollPeriod $period): bool
    {
        return $period->status === 'closed' || $period->runs->contains(fn (PayrollRun $r) => $r->isLocked());
    }

    /** Why a month may not pick the report up straight away. */
    private static function note(?PayrollPeriod $period): string
    {
        $open = $period?->runs->first(fn (PayrollRun $r) => ! $r->isLocked());

        return match ($open?->status) {
            null, 'draft' => '',
            'approved' => ' — payroll approved: reopen and recalculate it to include this',
            default => ' — payroll calculated: included when it is recalculated',
        };
    }
}
