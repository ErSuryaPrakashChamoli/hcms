<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Contracts\PayrollInputSource;
use App\Domain\Payroll\Models\BonusRunLine;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollComputation;

/**
 * HROne parity PO-22: processed bonus lines (approved by a second person) are paid once, with the payroll of their chosen
 * month or the first one calculated after it — the statutory bonus as a bonus-classified earning with the BONUS pay
 * component's own treatment, the ex gratia as a separate earning classified `ex_gratia` (never counted as statutory bonus
 * paid). Each payroll line carries the bonus line id; finalizing the run settles the bonus line as paid with the amounts
 * the payroll lines paid, so a later run never pays it again, and a bonus line another run not yet finalized carries is
 * left to that run. Without processed bonus lines nothing is added.
 */
final class BonusInput implements PayrollInputSource
{
    public const KEY = 'bonus';

    public const EX_GRATIA = 'ex_gratia';

    public function key(): string
    {
        return self::KEY;
    }

    public function inputsFor(PayrollComputation $c): array
    {
        $month = sprintf('%04d-%02d', $c->period->year, $c->period->month);
        $lines = BonusRunLine::query()->withoutGlobalScope(AccessScope::class)->with('run')->where('employee_id', $c->employee->id)
            ->where('status', 'processed')->whereNull('paid_payroll_run_id')->where('pay_month', '<=', $month)->orderBy('id')->sharedLock()->get();
        if ($lines->isEmpty()) {
            return [];
        }
        $elsewhere = app(PendingPayrollInputs::class)->pending($this->key(), (int) $c->employee->id);
        $component = SalaryComponent::query()->where('code', 'BONUS')->where('type', 'earning')->first()
            ?? SalaryComponent::query()->where('classification', 'bonus')->where('type', 'earning')->orderBy('id')->first();
        $treatment = [
            'taxable' => (bool) ($component?->taxable ?? true),
            'pf_applicable' => (bool) ($component?->pf_applicable ?? false),
            'esi_applicable' => (bool) ($component?->esi_applicable ?? false),
            'include_in_gross' => (bool) ($component?->include_in_gross ?? false),
        ];

        $out = [];
        foreach ($lines as $line) {
            if (isset($elsewhere[$line->id])) {
                continue;
            }
            $year = $line->run?->financial_year;
            $basis = ['bonus_run_line' => $line->id, 'financial_year' => $year, 'pay_month' => $line->pay_month];
            if ((float) $line->bonus_amount > 0) {
                $out[] = ['code' => $component?->code ?? 'BONUS', 'name' => 'Statutory bonus FY '.$year, 'type' => 'earning', 'amount' => round((float) $line->bonus_amount, 2),
                    'ids' => [$line->id], 'classification' => 'bonus', 'basis' => $basis + ['bonus_rate' => (float) $line->bonus_rate, 'bonus_wages' => (float) $line->bonus_wages]] + $treatment;
            }
            if ((float) $line->ex_gratia > 0) {
                $out[] = ['code' => 'EXGRATIA', 'name' => 'Ex gratia FY '.$year, 'type' => 'earning', 'amount' => round((float) $line->ex_gratia, 2),
                    'ids' => [$line->id], 'classification' => self::EX_GRATIA, 'basis' => $basis + ['reason' => $line->ex_gratia_reason],
                    'taxable' => $treatment['taxable'], 'pf_applicable' => false, 'esi_applicable' => false, 'include_in_gross' => $treatment['include_in_gross']];
            }
        }

        return $out;
    }

    public function settle(PayrollRun $run, array $idsByEmployee): void
    {
        $run->loadMissing('period');
        $ids = array_values(array_unique(array_merge(...array_values($idsByEmployee))));
        if ($ids === []) {
            return;
        }
        // What this run's lines actually paid for each bonus line (the payroll is the record of the money).
        $paid = [];
        PayrollEntryLine::query()->whereIn('payroll_entry_id', $run->entries()->withoutGlobalScope(AccessScope::class)->select('id'))->whereNotNull('basis')
            ->get(['id', 'payroll_entry_id', 'basis', 'amount', 'classification'])
            ->each(function (PayrollEntryLine $line) use (&$paid) {
                $source = $line->basis['source'] ?? null;
                if (is_array($source) && ($source['key'] ?? null) === self::KEY) {
                    foreach ((array) ($source['ids'] ?? []) as $id) {
                        $kind = $line->classification === self::EX_GRATIA ? 'ex_gratia' : 'bonus';
                        $paid[(int) $id][$kind] = ($paid[(int) $id][$kind] ?? 0.0) + (float) $line->amount;
                    }
                }
            });

        BonusRunLine::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $ids)->whereNull('paid_payroll_run_id')->get()
            ->each(fn (BonusRunLine $line) => $line->withAuditReason('Paid in payroll '.$run->period?->label().($line->status !== 'processed' ? ' (calculated before it was unprocessed)' : ''))->update([
                'status' => 'paid', 'paid_payroll_run_id' => $run->id, 'paid_at' => now(),
                'paid_bonus' => round($paid[$line->id]['bonus'] ?? 0.0, 2), 'paid_ex_gratia' => round($paid[$line->id]['ex_gratia'] ?? 0.0, 2),
            ]));
    }
}
