<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity PO-2 (matrix 25 §25.2.1 #2, §25.2.3 #1, "Tax regime"): the income-tax regime of each employee for a financial
 * year, read from their tax declaration of that year — the regime payroll's TDS uses (TaxComputer: the declaration's
 * regime, the new regime when there is no declaration). Read-only.
 */
final class DeclaredRegimes
{
    /** The register's choice for employees without a declaration of the year (payroll uses the new regime for them). */
    public const UNDECLARED = 'undeclared';

    public function __construct(private readonly FinancialYear $fy) {}

    /** @return array<string, string> regime => label, plus "no declaration" */
    public static function options(): array
    {
        return (array) config('peopleos.compliance.tax_regimes', ['new' => 'New regime', 'old' => 'Old regime'])
            + [self::UNDECLARED => 'No declaration (new regime by default)'];
    }

    public function yearOf(CarbonInterface|string|null $on = null): string
    {
        return $this->fy->label($on ?? today());
    }

    /**
     * The declared regime of each employee for the year holding the date; employees without a declaration are left out.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, string> employee id => regime
     */
    public function forEmployees(array $employeeIds, CarbonInterface|string|null $on = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($ids === []) {
            return [];
        }

        return EmployeeTaxDeclaration::query()->whereIn('employee_id', $ids)->where('financial_year', $this->yearOf($on))
            ->pluck('regime', 'employee_id')->mapWithKeys(fn ($regime, $id) => [(int) $id => (string) $regime])->all();
    }

    /**
     * Narrow a query to records of employees with one of the regimes (UNDECLARED = no declaration of the year).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $regimes
     * @return Builder<TModel>
     */
    public function whereRegime(Builder $query, string $employeeColumn, array $regimes, CarbonInterface|string|null $on = null): Builder
    {
        $regimes = array_values(array_intersect($regimes, array_keys(self::options())));
        if ($regimes === []) {
            return $query;
        }
        $declared = fn (?array $only) => EmployeeTaxDeclaration::query()->where('financial_year', $this->yearOf($on))
            ->when($only !== null, fn (Builder $q) => $q->whereIn('regime', $only))->select('employee_id');
        $chosen = array_values(array_diff($regimes, [self::UNDECLARED]));

        return $query->where(function (Builder $q) use ($employeeColumn, $chosen, $regimes, $declared) {
            if ($chosen !== []) {
                $q->orWhereIn($employeeColumn, $declared($chosen));
            }
            if (in_array(self::UNDECLARED, $regimes, true)) {
                $q->orWhereNotIn($employeeColumn, $declared(null));
            }
        });
    }
}
