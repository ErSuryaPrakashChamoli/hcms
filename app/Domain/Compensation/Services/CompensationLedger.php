<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructureVersion;
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
 * (employee_salary_assignments), which exist only for approved compensation, and only approved
 * structure versions. Never writes.
 *
 * A payroll segment is the part of the window where one compensation row and one structure version are
 * both in force, so historical periods resolve to the composition in force at the time. A part of a
 * row with no approved structure version yields a segment with no components (Payroll reports it as
 * an exception instead of paying anything).
 */
final class CompensationLedger implements CompensationOutput
{
    public function on(Employee $employee, CarbonInterface|string|null $date = null): ?CompensationSnapshot
    {
        $row = EmployeeSalaryAssignment::query()->active()->where('employee_id', $employee->getKey())->effectiveOn($date)
            ->with('structure:id,code')->orderByDesc('effective_from')->orderByDesc('id')->first();
        if ($row === null) {
            return null;
        }
        $version = SalaryStructureVersion::query()->where('salary_structure_id', $row->salary_structure_id)->whereIn('status', SalaryStructureVersion::APPROVED)
            ->effectiveOn($date)->orderByDesc('effective_from')->value('id');

        return CompensationSnapshot::fromRow($row, $row->structure?->code, $version ? (int) $version : null);
    }

    public function forPayroll(Employee $employee, CarbonInterface $from, CarbonInterface $to): PayrollCompensation
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $rows = $this->overlapping(EmployeeSalaryAssignment::query(), $start, $end)
            ->where('employee_id', $employee->getKey())
            ->with('structure:id,code')
            ->orderBy('effective_from')->orderBy('id')
            ->get();
        $versions = $this->versions($rows->pluck('salary_structure_id')->all(), $start, $end, withComponents: true);

        $segments = collect();
        foreach ($rows as $row) {
            $rowFrom = $row->effective_from->copy()->startOfDay()->max($start)->copy();
            $rowTo = ($row->effective_to ? $row->effective_to->copy()->startOfDay() : $end->copy())->min($end)->copy();
            if ($rowFrom->gt($rowTo)) {
                continue;
            }
            $cursor = $rowFrom->copy();
            foreach ($versions->get($row->salary_structure_id, collect()) as $version) {
                $vFrom = $version->effective_from->copy()->startOfDay()->max($rowFrom)->copy();
                $vTo = ($version->effective_to ? $version->effective_to->copy()->startOfDay() : $rowTo->copy())->min($rowTo)->copy();
                if ($vFrom->gt($vTo)) {
                    continue;
                }
                if ($cursor->lt($vFrom)) {
                    $segments->push($this->segment($row, $cursor, $vFrom->copy()->subDay(), null));
                }
                $segments->push($this->segment($row, $vFrom, $vTo, $version));
                $cursor = $vTo->copy()->addDay();
            }
            if ($cursor->lte($rowTo)) {
                $segments->push($this->segment($row, $cursor, $rowTo, null));
            }
        }

        return new PayrollCompensation($segments->values(), self::fingerprintOf($rows->map(fn ($r) => [$r->id, $r->effective_from, $r->effective_to, $r->salary_structure_id]), $versions, $start, $end), self::VERSION);
    }

    public function fingerprints(array $employeeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $rows = $this->overlapping(EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class), $start, $end)
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('id')
            ->toBase()->get(['id', 'employee_id', 'effective_from', 'effective_to', 'salary_structure_id']);
        $versions = $this->versions($rows->pluck('salary_structure_id')->unique()->all(), $start, $end);
        $byEmployee = $rows->groupBy('employee_id');

        $result = [];
        foreach ($employeeIds as $id) {
            $result[$id] = self::fingerprintOf(collect($byEmployee->get($id, []))->map(fn ($r) => [$r->id, $r->effective_from, $r->effective_to, $r->salary_structure_id]), $versions, $start, $end);
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

    /**
     * Fingerprint of what a payroll calculation used: the compensation rows and the approved structure
     * versions of their structures, each with its effective range clipped to the window (a row or
     * version closed after the window does not change what the window used; amounts never change).
     *
     * @param  Collection<int, array{0: int, 1: mixed, 2: mixed, 3: int}>  $rows
     * @param  Collection<int, Collection<int, SalaryStructureVersion>>  $versions  by structure id
     */
    public static function fingerprintOf(Collection $rows, Collection $versions, CarbonInterface $start, CarbonInterface $end): string
    {
        $from = fn ($v) => max(substr((string) ($v instanceof CarbonInterface ? $v->toDateString() : $v), 0, 10), $start->toDateString());
        $to = fn ($v) => min($v === null ? $end->toDateString() : substr((string) ($v instanceof CarbonInterface ? $v->toDateString() : $v), 0, 10), $end->toDateString());
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = 'a'.$r[0].':'.$from($r[1]).':'.$to($r[2]);
            foreach ($versions->get((int) $r[3], collect()) as $v) {
                $parts[] = 'v'.$v->id.':'.$from($v->effective_from).':'.$to($v->effective_to);
            }
        }
        $parts = array_values(array_unique($parts));
        sort($parts);

        return sha1(self::VERSION.'|'.implode('|', $parts));
    }

    private function overlapping(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->where('status', 'active')
            ->where('effective_from', '<=', $end->toDateString().' 23:59:59')
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start->toDateString()));
    }

    /**
     * Approved versions of the structures overlapping the window, oldest first, grouped by structure.
     *
     * @param  list<int>  $structureIds
     * @return Collection<int, Collection<int, SalaryStructureVersion>>
     */
    private function versions(array $structureIds, Carbon $start, Carbon $end, bool $withComponents = false): Collection
    {
        if ($structureIds === []) {
            return collect();
        }

        return SalaryStructureVersion::query()->whereIn('salary_structure_id', array_values(array_unique($structureIds)))
            ->whereIn('status', SalaryStructureVersion::APPROVED)
            ->where('effective_from', '<=', $end->toDateString().' 23:59:59')
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start->toDateString()))
            ->when($withComponents, fn (Builder $q) => $q->with('components.component'))
            ->orderBy('effective_from')->orderBy('id')->get()
            ->groupBy('salary_structure_id');
    }

    private function segment(EmployeeSalaryAssignment $row, Carbon $from, Carbon $to, ?SalaryStructureVersion $version): CompensationSegment
    {
        $components = ($version?->components ?? collect())
            ->filter(fn ($item) => $item->component !== null)
            ->map(fn ($item) => new CompensationComponentLine($item->component, $item->formula_override, (int) $item->sort_order))
            ->values();

        return new CompensationSegment(CompensationSnapshot::fromRow($row, $row->structure?->code, $version?->id), $from->copy(), $to->copy(), $components);
    }
}
