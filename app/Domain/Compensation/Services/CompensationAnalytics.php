<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationRange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 11 compensation analytics (§24, §43): facts only, in a constant number of queries, within the
 * viewer's organisation scope, as of a date.
 *
 * Privacy: the established small-group rule (peopleos.compensation.analytics_min_group, 5, the same
 * threshold as workforce, talent and learning). A group of fewer people shows its count band only —
 * never an amount — and when the filtered population itself is below the threshold nothing but that
 * fact is returned, so a filter cannot isolate a person. Amounts are never added across currencies.
 *
 * No recommendation, fairness verdict, performance inference or attrition prediction is produced.
 */
final class CompensationAnalytics
{
    public function __construct(private readonly AccessScopes $scopes, private readonly CompensationRanges $ranges) {}

    /**
     * @param  array{company_id?: int|null, department_id?: int|null, location_id?: int|null, grade_id?: int|null, employment_type_id?: int|null}  $filters
     * @return array<string, mixed>
     */
    public function summary(User $viewer, array $filters = [], ?string $asOf = null): array
    {
        if (! $viewer->hasPermission('compensation.analytics') && ! $viewer->hasPermission('compensation.view')) {
            throw new CompensationRuleViolation('This needs compensation.analytics.');
        }
        $asOf = Carbon::parse($asOf ?? now())->toDateString();
        $min = max(1, (int) config('peopleos.compensation.analytics_min_group', 5));
        $rows = $this->rows($viewer, $filters, $asOf);
        $base = ['as_of' => $asOf, 'min_group' => $min, 'filters' => array_filter($filters), 'basis' => 'Annual CTC of approved compensation in force on the date'];
        if ($rows->count() < $min) {
            return $base + ['suppressed' => true, 'population' => $rows->isEmpty() ? 0 : "fewer than {$min}", 'note' => "Fewer than {$min} people match; no figures are shown."];
        }

        $byCurrency = $rows->groupBy('currency');
        $ranges = $this->rangesIndex($asOf);
        $bands = $rows->map(fn ($r) => $this->band($r, $ranges));

        return $base + [
            'suppressed' => false,
            'population' => $rows->count(),
            'totals' => $byCurrency->map(fn (Collection $g) => $this->amounts($g, $min))->all(),
            'by_grade' => $this->grouped($rows, 'grade_id', Grade::class, $min),
            'by_department' => $this->grouped($rows, 'department_id', Department::class, $min),
            'by_location' => $this->grouped($rows, 'location_id', Location::class, $min),
            'by_employment_type' => $this->grouped($rows, 'employment_type_id', EmploymentType::class, $min),
            'range_penetration' => $this->penetration($rows, $bands, $min),
            'changes' => $this->changes($viewer, $filters, $asOf, $min),
        ];
    }

    /** @return Collection<int, object> one row per employee: amounts and dimensions on the date */
    private function rows(User $viewer, array $filters, string $asOf): Collection
    {
        $positions = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->effectiveOn($asOf)
            ->select(['employee_id', 'company_id', 'department_id', 'location_id', 'grade_id', 'designation_id', 'employment_type_id'])
            ->toBase()->get()->keyBy('employee_id');
        $query = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->effectiveOn($asOf)
            ->when(app(AccessScopes::class)->isScoped($viewer), fn ($q) => $q->whereIn('employee_id', $this->scopes->employeeKeys($viewer)));

        return $query->toBase()->get(['employee_id', 'ctc_annual', 'variable_target_annual', 'currency', 'salary_structure_id'])
            ->map(function ($r) use ($positions) {
                $p = $positions->get($r->employee_id);

                return (object) ['employee_id' => (int) $r->employee_id, 'ctc' => (float) $r->ctc_annual, 'variable' => $r->variable_target_annual !== null ? (float) $r->variable_target_annual : null, 'currency' => $r->currency, 'structure_id' => (int) $r->salary_structure_id,
                    'company_id' => $p?->company_id, 'department_id' => $p?->department_id, 'location_id' => $p?->location_id, 'grade_id' => $p?->grade_id, 'designation_id' => $p?->designation_id, 'employment_type_id' => $p?->employment_type_id];
            })
            ->filter(function ($r) use ($filters) {
                foreach (['company_id', 'department_id', 'location_id', 'grade_id', 'employment_type_id'] as $dim) {
                    if (filled($filters[$dim] ?? null) && (int) $r->{$dim} !== (int) $filters[$dim]) {
                        return false;
                    }
                }

                return true;
            })->values();
    }

    /** @return array<string, mixed> */
    private function amounts(Collection $group, int $min): array
    {
        if ($group->count() < $min) {
            return ['people' => "fewer than {$min}", 'suppressed' => true];
        }
        $variable = $group->whereNotNull('variable');

        return ['people' => $group->count(), 'suppressed' => false, 'total_fixed' => round($group->sum('ctc'), 2), 'average_fixed' => round($group->avg('ctc'), 2),
            'total_variable_target' => round($variable->sum('variable'), 2), 'with_variable_target' => $variable->count()];
    }

    /** @return list<array<string, mixed>> */
    private function grouped(Collection $rows, string $dimension, string $model, int $min): array
    {
        $names = $model::query()->whereIn('id', $rows->pluck($dimension)->filter()->unique())->pluck('name', 'id');

        return $rows->groupBy(fn ($r) => ($r->{$dimension} ?? 0).'|'.$r->currency)->map(function (Collection $g, string $key) use ($names, $min) {
            [$id, $currency] = explode('|', $key);

            return ['id' => (int) $id ?: null, 'name' => $names[(int) $id] ?? 'Not set', 'currency' => $currency] + $this->amounts($g, $min);
        })->sortBy('name')->values()->all();
    }

    /** @return array<string, mixed> */
    private function penetration(Collection $rows, Collection $bands, int $min): array
    {
        $counts = ['below' => 0, 'within' => 0, 'above' => 0, 'no_range' => 0];
        $compa = [];
        foreach ($bands as $i => $band) {
            $counts[$band['band'] ?? 'no_range']++;
            if ($band['compa_ratio'] !== null) {
                $compa[] = $band['compa_ratio'];
            }
        }
        $suppress = fn (int $n) => $n > 0 && $n < $min ? "fewer than {$min}" : $n;

        return [
            'definition' => 'Range position = (CTC − minimum) / (maximum − minimum); compa-ratio = CTC / midpoint. Descriptive arithmetic only.',
            'below' => $suppress($counts['below']), 'within' => $suppress($counts['within']), 'above' => $suppress($counts['above']), 'no_range' => $suppress($counts['no_range']),
            'average_compa_ratio' => count($compa) >= $min ? round(array_sum($compa) / count($compa), 4) : null,
        ];
    }

    /** @return array<string, mixed> change counts by type and status (no amounts) */
    private function changes(User $viewer, array $filters, string $asOf, int $min): array
    {
        $from = Carbon::parse($asOf)->subYear()->toDateString();
        $counts = CompensationChange::query()->withoutGlobalScope(AccessScope::class)
            ->when(app(AccessScopes::class)->isScoped($viewer), fn ($q) => $q->whereIn('employee_id', $this->scopes->employeeKeys($viewer)))
            ->whereBetween('effective_from', [$from, $asOf.' 23:59:59'])
            ->selectRaw('change_type, status, COUNT(*) AS n')->groupBy('change_type', 'status')->toBase()->get();

        return ['window' => [$from, $asOf], 'rows' => $counts->map(fn ($r) => ['change_type' => $r->change_type, 'status' => $r->status, 'count' => (int) $r->n < $min ? "fewer than {$min}" : (int) $r->n])->values()->all()];
    }

    /** @return Collection<int, CompensationRange> approved ranges in force on the date */
    private function rangesIndex(string $asOf): Collection
    {
        return CompensationRange::query()->whereIn('status', ['approved', 'superseded'])->effectiveOn($asOf)->get();
    }

    /** @return array{band: ?string, compa_ratio: ?float} */
    private function band(object $row, Collection $ranges): array
    {
        if ($row->grade_id === null) {
            return ['band' => null, 'compa_ratio' => null];
        }
        $range = $ranges->filter(fn (CompensationRange $r) => (int) $r->grade_id === (int) $row->grade_id && $r->currency === $row->currency
            && ($r->company_id === null || (int) $r->company_id === (int) $row->company_id) && ($r->designation_id === null || (int) $r->designation_id === (int) $row->designation_id)
            && ($r->salary_structure_id === null || (int) $r->salary_structure_id === $row->structure_id))
            ->sortByDesc(fn (CompensationRange $r) => $r->specificity())->first();
        $position = $this->ranges->position($row->ctc, $range, $row->currency);

        return ['band' => $position['band'], 'compa_ratio' => $position['compa_ratio']];
    }
}
