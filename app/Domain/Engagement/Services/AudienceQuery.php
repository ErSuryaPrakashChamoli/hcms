<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Phase 13: resolves audience criteria to employees in SQL, never by loading employees into PHP.
 *
 * Every criterion is checked against effective-dated records on the resolution date:
 * - the employee's position (company, location, business unit, division, department, team,
 *   designation, level, grade, employment type, position);
 * - establishment assignments;
 * - line-manager relationships;
 * - lifecycle states (employed states by default);
 * - explicitly listed employees.
 *
 * The result is always constrained to the scope user's organisation and relationship scope
 * (AccessScopes::employeeKeys), so nobody can target people outside their scope. Within one
 * dimension values are OR-ed; across dimensions they are AND-ed.
 */
final class AudienceQuery
{
    /** criteria key => employee_positions column */
    public const POSITION_DIMENSIONS = [
        'company_ids' => 'company_id', 'location_ids' => 'location_id', 'business_unit_ids' => 'business_unit_id', 'division_ids' => 'division_id',
        'department_ids' => 'department_id', 'team_ids' => 'team_id', 'designation_ids' => 'designation_id', 'level_ids' => 'level_id',
        'grade_ids' => 'grade_id', 'employment_type_ids' => 'employment_type_id', 'position_ids' => 'position_id',
    ];

    public const OTHER_KEYS = ['establishment_ids', 'manager_employee_ids', 'employee_ids', 'lifecycle_states'];

    public function __construct(private readonly AccessScopes $scopes) {}

    /** @return array<string, list<int|string>> normalised criteria (unknown keys refused, empty values dropped) */
    public function normalise(array $criteria): array
    {
        $unknown = array_diff(array_keys($criteria), [...array_keys(self::POSITION_DIMENSIONS), ...self::OTHER_KEYS]);
        if ($unknown !== []) {
            throw new EngagementRuleViolation('Unknown audience criteria: '.implode(', ', $unknown).'.');
        }
        $states = array_map(fn (LifecycleState $s) => $s->value, LifecycleState::cases());
        $out = [];
        foreach ($criteria as $key => $values) {
            $values = array_values(array_unique(array_filter((array) $values, fn ($v) => $v !== null && $v !== '')));
            if ($values === []) {
                continue;
            }
            if ($key === 'lifecycle_states') {
                if (array_diff($values, $states) !== []) {
                    throw new EngagementRuleViolation('Unknown lifecycle state in the audience.');
                }
                $out[$key] = array_map('strval', $values);

                continue;
            }
            $out[$key] = array_map('intval', $values);
            sort($out[$key]);
        }
        ksort($out);

        return $out;
    }

    /**
     * Employees matching the criteria on the date, inside the scope user's reach. Without criteria the
     * audience is everyone employed in that scope. Returns an employee query (ids are selected by the caller).
     */
    public function query(array $criteria, ?User $scopeUser, CarbonInterface|string|null $on = null): Builder
    {
        $criteria = $this->normalise($criteria);
        $day = Carbon::parse($on ?? now())->toDateString();

        return AccessScope::withoutScoping(function () use ($criteria, $scopeUser, $day) {
            $query = Employee::query()->select('employees.id');

            if (isset($criteria['lifecycle_states'])) {
                $query->whereIn('employees.lifecycle_state', $criteria['lifecycle_states']);
            } else {
                $query->employed();
            }

            $positionCriteria = array_intersect_key($criteria, self::POSITION_DIMENSIONS);
            if ($positionCriteria !== []) {
                $positions = EmployeePosition::query()->select('employee_id')->effectiveOn($day);
                foreach ($positionCriteria as $key => $ids) {
                    $positions->whereIn(self::POSITION_DIMENSIONS[$key], $ids);
                }
                $query->whereIn('employees.id', $positions);
            }

            if (isset($criteria['establishment_ids'])) {
                $query->whereIn('employees.id', EmployeeEstablishmentAssignment::query()->select('employee_id')->effectiveOn($day)->whereIn('establishment_id', $criteria['establishment_ids']));
            }

            if (isset($criteria['manager_employee_ids'])) {
                $query->whereIn('employees.id', ReportingRelationship::query()->select('employee_id')->effectiveOn($day)
                    ->whereIn('type', config('peopleos.performance.manager_relationship_types', ['line']))->whereIn('manager_id', $criteria['manager_employee_ids']));
            }

            if (isset($criteria['employee_ids'])) {
                $query->whereIn('employees.id', $criteria['employee_ids']);
            }

            if ($scopeUser !== null && ! $scopeUser->isPlatformAdmin()) {
                $query->whereIn('employees.id', $this->scopes->employeeKeys($scopeUser));
            }

            return $query;
        });
    }

    public function count(array $criteria, ?User $scopeUser, CarbonInterface|string|null $on = null): int
    {
        return AccessScope::withoutScoping(fn () => $this->query($criteria, $scopeUser, $on)->count());
    }

    /** True when every criterion names only organisation units the user may reach (no out-of-scope targeting). */
    public function withinScope(array $criteria, User $user): bool
    {
        $criteria = $this->normalise($criteria);
        if (isset($criteria['employee_ids']) || isset($criteria['manager_employee_ids'])) {
            $ids = [...($criteria['employee_ids'] ?? []), ...($criteria['manager_employee_ids'] ?? [])];
            $reachable = AccessScope::withoutScoping(fn () => Employee::query()->whereIn('id', $ids)->whereIn('id', $this->scopes->employeeKeys($user))->count());
            if ($reachable !== count(array_unique($ids))) {
                return false;
            }
        }
        $scope = $this->scopes->for($user);
        if ($scope === null) {
            return true;
        }
        foreach (self::POSITION_DIMENSIONS as $key => $column) {
            $dimension = substr($column, 0, -3);
            if (isset($criteria[$key], $scope[$dimension]) && array_diff($criteria[$key], $scope[$dimension]) !== []) {
                return false;
            }
        }
        if (isset($criteria['establishment_ids'], $scope['establishment']) && array_diff($criteria['establishment_ids'], $scope['establishment']) !== []) {
            return false;
        }

        return true;
    }
}
