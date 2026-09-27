<?php

namespace App\Domain\Compliance\Services\Returns;

use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads FINALIZED (or paid) payroll for statutory outputs. Never reads draft runs and never writes
 * payroll. Entries calculated before Phase 5 carry no establishment; those are attributed through
 * the employee's establishment assignment on the period end date and flagged as such.
 */
final class StatutoryPayrollSource
{
    public function __construct(private readonly EstablishmentAssignments $assignments) {}

    /** @return Collection<int, PayrollRun> finalized/paid runs of the company whose period starts in the range */
    public function runs(int $companyId, Carbon|string $from, Carbon|string $to): Collection
    {
        return PayrollRun::query()->with('period')
            ->where('company_id', $companyId)->whereIn('status', ['finalized', 'paid'])
            ->whereHas('period', fn ($q) => $q->whereDate('start_date', '>=', Carbon::parse($from)->toDateString())->whereDate('start_date', '<=', Carbon::parse($to)->toDateString()))
            ->orderBy('id')->get();
    }

    /** Runs of the company in the range that are NOT finalized yet (a wage-period validation). */
    public function unfinalizedRuns(int $companyId, Carbon|string $from, Carbon|string $to): Collection
    {
        return PayrollRun::query()->where('company_id', $companyId)->whereNotIn('status', ['finalized', 'paid'])
            ->whereHas('period', fn ($q) => $q->whereDate('start_date', '>=', Carbon::parse($from)->toDateString())->whereDate('start_date', '<=', Carbon::parse($to)->toDateString()))
            ->get();
    }

    /**
     * Payroll entries of those runs for an establishment (or a legal entity when $establishmentId is null).
     *
     * @return Collection<int, array{entry: PayrollEntry, establishment_source: string}>
     */
    public function entries(Collection $runs, ?int $establishmentId, ?int $legalEntityId = null): Collection
    {
        if ($runs->isEmpty()) {
            return collect();
        }

        $periods = $runs->mapWithKeys(fn (PayrollRun $run) => [$run->id => $run->period]);

        return PayrollEntry::query()->with(['lines', 'employee.person', 'employee.statutoryDetail', 'run.period'])
            ->whereIn('payroll_run_id', $runs->pluck('id'))
            ->orderBy('payroll_run_id')->orderBy('employee_id')
            ->get()
            ->map(function (PayrollEntry $entry) use ($periods, $establishmentId, $legalEntityId) {
                $source = 'payroll_entry';
                $entryEstablishment = $entry->establishment_id;
                $entryEntity = $entry->legal_entity_id;

                if ($entryEstablishment === null) {
                    $resolved = $this->assignments->resolve($entry->employee_id, $periods[$entry->payroll_run_id]->end_date)['establishment'];
                    $entryEstablishment = $resolved?->getKey();
                    $entryEntity = $resolved?->legal_entity_id;
                    $source = 'resolved_at_return';
                }

                $matches = $establishmentId !== null ? (int) $entryEstablishment === $establishmentId : (int) $entryEntity === (int) $legalEntityId;

                return $matches ? ['entry' => $entry, 'establishment_source' => $source] : null;
            })
            ->filter()
            ->values();
    }
}
