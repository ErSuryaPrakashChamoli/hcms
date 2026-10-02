<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Support\CompensationComponentLine;
use App\Domain\Compensation\Support\CompensationSegment;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Compensation\Support\PayrollCompensation;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 11: the CompensationOutput implementation. Reads only active rows of the canonical timeline
 * (employee_salary_assignments), which exist only for approved compensation. Never writes.
 */
final class CompensationLedger implements CompensationOutput
{
    public function on(Employee $employee, CarbonInterface|string|null $date = null): ?CompensationSnapshot
    {
        $row = EmployeeSalaryAssignment::query()->active()->where('employee_id', $employee->getKey())->effectiveOn($date)
            ->with('structure:id,code')->orderByDesc('effective_from')->orderByDesc('id')->first();

        return $row ? CompensationSnapshot::fromRow($row, $row->structure?->code) : null;
    }

    public function forPayroll(Employee $employee, CarbonInterface $from, CarbonInterface $to): PayrollCompensation
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $rows = $this->overlapping(EmployeeSalaryAssignment::query(), $start, $end)
            ->where('employee_id', $employee->getKey())
            ->with('structure.items.component')
            ->orderBy('effective_from')->orderBy('id')
            ->get();

        $segments = $rows->map(fn (EmployeeSalaryAssignment $row) => new CompensationSegment(
            CompensationSnapshot::fromRow($row, $row->structure?->code),
            $row->effective_from->copy()->startOfDay()->max($start)->copy(),
            ($row->effective_to ? $row->effective_to->copy()->startOfDay() : $end->copy())->min($end)->copy(),
            $this->components($row),
        ))->filter(fn (CompensationSegment $s) => $s->from->lte($s->to))->values();

        return new PayrollCompensation($segments, self::fingerprintOf($rows->map(fn ($r) => [$r->id, $r->effective_from, $r->effective_to])), self::VERSION);
    }

    public function fingerprints(array $employeeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }
        $rows = $this->overlapping(EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class), Carbon::parse($from)->startOfDay(), Carbon::parse($to)->startOfDay())
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('id')
            ->toBase()->get(['id', 'employee_id', 'effective_from', 'effective_to'])
            ->groupBy('employee_id');

        $result = [];
        foreach ($employeeIds as $id) {
            $result[$id] = self::fingerprintOf(collect($rows->get($id, []))->map(fn ($r) => [$r->id, $r->effective_from, $r->effective_to]));
        }

        return $result;
    }

    public function history(Employee|int $employee): Collection
    {
        return EmployeeSalaryAssignment::query()->active()
            ->where('employee_id', $employee instanceof Employee ? $employee->getKey() : $employee)
            ->with('structure:id,code')->orderBy('effective_from')->orderBy('id')->get()
            ->map(fn (EmployeeSalaryAssignment $row) => CompensationSnapshot::fromRow($row, $row->structure?->code))
            ->values();
    }

    public function coveredEmployeeIds(CarbonInterface|string|null $date = null): QueryBuilder
    {
        return EmployeeSalaryAssignment::query()->active()->effectiveOn($date)->select('employee_id')->toBase();
    }

    public function annualCtcOn(?array $employeeIds = null, CarbonInterface|string|null $date = null): array
    {
        return EmployeeSalaryAssignment::query()->active()->effectiveOn($date)
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $employeeIds))
            ->orderBy('effective_from')->toBase()->get(['employee_id', 'ctc_annual'])
            ->mapWithKeys(fn ($r) => [(int) $r->employee_id => (float) $r->ctc_annual])->all();
    }

    /** Fingerprint of the canonical rows used: ids and their effective ranges (amounts never change). */
    public static function fingerprintOf(Collection $rows): string
    {
        $parts = $rows->map(fn (array $r) => $r[0].':'.substr((string) ($r[1] instanceof CarbonInterface ? $r[1]->toDateString() : $r[1]), 0, 10).':'.substr((string) ($r[2] instanceof CarbonInterface ? $r[2]->toDateString() : ($r[2] ?? '')), 0, 10))
            ->sort()->values()->all();

        return sha1(self::VERSION.'|'.implode('|', $parts));
    }

    private function overlapping(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->where('status', 'active')
            ->where('effective_from', '<=', $end->toDateString().' 23:59:59')
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start->toDateString()));
    }

    /** @return Collection<int, CompensationComponentLine> */
    private function components(EmployeeSalaryAssignment $row): Collection
    {
        return ($row->structure?->items ?? collect())
            ->filter(fn ($item) => $item->component !== null)
            ->map(fn ($item) => new CompensationComponentLine($item->component, $item->formula_override, (int) $item->sort_order))
            ->values();
    }
}
