<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Expense\Contracts\PayrollCommitments;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Models\PayrollEntry;

/**
 * HROne setup parity EX-E: the source records a payroll run not yet finalized has calculated lines for, read from the
 * entries' `inputs.input_sources` (what PayrollCalculator::inputSources recorded). Finalization settles them; until then
 * the owning module keeps every other payment or recovery of them away.
 */
final class PendingPayrollInputs implements PayrollCommitments
{
    public function pending(string $key, int $employeeId): array
    {
        $pending = [];
        PayrollEntry::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)
            ->whereHas('run', fn ($q) => $q->whereNotIn('status', ['finalized', 'paid']))
            ->with('run.period')->get(['id', 'payroll_run_id', 'inputs'])
            ->each(function (PayrollEntry $entry) use ($key, &$pending) {
                foreach ((array) ($entry->inputs['input_sources'][$key] ?? []) as $id) {
                    $pending[(int) $id] = 'the payroll of '.($entry->run?->period?->label() ?? 'a period being prepared');
                }
            });

        return $pending;
    }
}
