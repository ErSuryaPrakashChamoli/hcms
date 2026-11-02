<?php

namespace App\Domain\Employment\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Department;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * HROne parity WO-16 (matrix 23 §23.9 #4, #7): who a bulk change is for, chosen once in a wizard.
 *
 * - `team`: the people who report to one manager today through one relationship (the reporting team = line, the
 *   functional team = functional), leavers left out.
 * - `filters`: the WO-2 employee filters — organisation (company, business unit, division, department with its
 *   sub-departments, designation, grade, level, location), status (lifecycle state), employment type, joining date and
 *   "added on" ranges, and whether the employee already has a sign-in. At least one filter, or "everyone" ticked on
 *   purpose, so a whole organisation is never chosen by accident.
 * - `codes`: a pasted list of employee codes (at most `peopleos.bulk.pasted_codes_max`).
 *
 * Read only. Every query is an Employee query, so the person's access scope (AccessScope) narrows it; the bulk run then
 * checks each record again when it acts on it.
 */
final class EmployeeSelection
{
    public const SOURCES = ['team' => "A manager's team", 'filters' => 'Employees matching filters', 'codes' => 'Pasted employee codes'];

    public const TEAM_TYPES = ['line' => 'Reporting team', 'functional' => 'Functional team'];

    /** Sign-in state of the employee's login (`users.status`, or none linked). */
    public const SIGN_IN = ['none' => 'No sign-in yet', 'invited' => 'Invited, not accepted yet', 'active' => 'Active sign-in', 'suspended' => 'Suspended sign-in'];

    /** Position columns the filters offer (all multi-selects on the current position). */
    public const ORGANISATION = ['company_id', 'business_unit_id', 'division_id', 'department_id', 'designation_id', 'grade_id', 'level_id', 'location_id', 'employment_type_id'];

    /** @param  array<string, mixed>  $criteria */
    public function query(array $criteria): Builder
    {
        return match ((string) ($criteria['source'] ?? '')) {
            'team' => $this->teamQuery($criteria),
            'filters' => $this->filterQuery($criteria),
            'codes' => $this->codesQuery($criteria),
            default => throw new RuntimeException('Choose who the change is for: a manager\'s team, filters or pasted codes.'),
        };
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return list<int> the employees chosen (at most $limit; ordered by id)
     */
    public function ids(array $criteria, int $limit): array
    {
        return $this->query($criteria)->orderBy('employees.id')->limit(max(1, $limit))->pluck('employees.id')->map(fn ($id) => (int) $id)->all();
    }

    /** People who report to $manager through $type on $on (today by default), leavers left out. */
    public function team(Employee $manager, string $type = 'line', CarbonInterface|string|null $on = null): Builder
    {
        $type = array_key_exists($type, (array) config('peopleos.people.reporting_types', [])) ? $type : 'line';
        $day = Carbon::parse($on ?? now())->toDateString();

        return Employee::query()
            ->whereHas('reportingRelationships', fn (Builder $r) => $r->where('manager_id', $manager->getKey())->where('type', $type)->effectiveOn($day))
            ->whereNotIn('lifecycle_state', [LifecycleState::Exited->value, LifecycleState::Alumni->value, LifecycleState::NotJoined->value]);
    }

    /** @return list<string> codes from a pasted list (comma, semicolon, space or new line), unique, capped */
    public static function parseCodes(mixed $pasted): array
    {
        $parts = is_array($pasted) ? $pasted : (preg_split('/[\s,;]+/', (string) $pasted) ?: []);
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), $parts), fn (string $c) => $c !== '' && mb_strlen($c) <= 64)));

        return array_slice($codes, 0, max(1, (int) config('peopleos.bulk.pasted_codes_max', 1000)));
    }

    /** @param  array<string, mixed>  $criteria */
    private function teamQuery(array $criteria): Builder
    {
        $manager = Employee::query()->find((int) ($criteria['team_of'] ?? 0));
        if ($manager === null) {
            throw new RuntimeException('Choose the manager whose team moves.');
        }

        return $this->team($manager, (string) ($criteria['team_type'] ?? 'line'));
    }

    /** @param  array<string, mixed>  $criteria */
    private function codesQuery(array $criteria): Builder
    {
        $codes = self::parseCodes($criteria['codes'] ?? null);
        if ($codes === []) {
            throw new RuntimeException('Paste at least one employee code.');
        }

        return Employee::query()->whereIn('employees.employee_code', $codes);
    }

    /** @param  array<string, mixed>  $criteria */
    private function filterQuery(array $criteria): Builder
    {
        $position = [];
        foreach (self::ORGANISATION as $column) {
            $ids = array_values(array_filter(array_map('intval', (array) ($criteria[$column] ?? []))));
            if ($ids !== []) {
                $position[$column] = $column === 'department_id' ? self::withSubDepartments($ids) : $ids;
            }
        }
        $states = array_values(array_filter((array) ($criteria['lifecycle_state'] ?? []), fn ($v) => is_string($v) && LifecycleState::tryFrom($v) !== null));
        $signIn = array_values(array_intersect(array_keys(self::SIGN_IN), (array) ($criteria['sign_in'] ?? [])));
        $dates = [];
        foreach (['joined' => 'joining_date', 'created' => 'created_at'] as $prefix => $column) {
            foreach (['from' => '>=', 'until' => '<='] as $end => $operator) {
                $value = $criteria["{$prefix}_{$end}"] ?? null;
                if (filled($value)) {
                    $dates[] = [$column, $operator, $this->day($value, $end === 'until')];
                }
            }
        }
        $narrowed = $position !== [] || $states !== [] || $dates !== [];
        if (! $narrowed && ! ($criteria['everyone'] ?? false)) {
            throw new RuntimeException('Choose at least one filter, or tick "Everyone in my access", so the whole organisation is never chosen by accident.');
        }

        return Employee::query()
            ->when($position !== [], fn (Builder $q) => $q->whereHas('currentPosition', function (Builder $p) use ($position) {
                foreach ($position as $column => $ids) {
                    $p->whereIn($p->getModel()->qualifyColumn($column), $ids);
                }
            }))
            ->when($states !== [], fn (Builder $q) => $q->whereIn('employees.lifecycle_state', $states))
            ->when($dates !== [], function (Builder $q) use ($dates) {
                foreach ($dates as [$column, $operator, $value]) {
                    $q->where('employees.'.$column, $operator, $value);
                }
            })
            ->when($signIn !== [], fn (Builder $q) => $q->where(function (Builder $w) use ($signIn) {
                if (in_array('none', $signIn, true)) {
                    $w->orWhereNull('employees.user_id');
                }
                $statuses = array_values(array_intersect([UserStatus::Invited->value, UserStatus::Active->value, UserStatus::Suspended->value], $signIn));
                if ($statuses !== []) {
                    $w->orWhereHas('user', fn (Builder $u) => $u->whereIn('status', $statuses));
                }
            }));
    }

    private function day(mixed $value, bool $endOfDay): string
    {
        try {
            $day = Carbon::parse((string) $value);
        } catch (Throwable) {
            throw new RuntimeException('Give the dates as dates.');
        }

        return ($endOfDay ? $day->endOfDay() : $day->startOfDay())->toDateTimeString();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int> the departments and every department below them (as the WO-2 organisation filter)
     */
    public static function withSubDepartments(array $ids): array
    {
        $all = $ids;
        $frontier = $ids;
        for ($depth = 0; $depth < 20 && $frontier !== []; $depth++) {
            $frontier = array_values(array_diff(Department::query()->whereIn('parent_department_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all(), $all));
            $all = [...$all, ...$frontier];
        }

        return array_values(array_unique($all));
    }
}
