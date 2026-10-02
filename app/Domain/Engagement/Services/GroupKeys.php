<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Location;

/**
 * Phase 13: assigns each eligible employee the group key of the survey's single pinned breakdown
 * dimension, at the audience snapshot. Groups are sized by eligible employees, not respondents.
 *
 * - A group with at least k eligible people keeps its own key ("department:12").
 * - Smaller groups and people without a value form "other".
 * - If "other" would be smaller than k, the smallest named group is merged into it.
 * - If fewer than two groups remain, nobody gets a key (results are overall only).
 *
 * So a key never narrows a response down to fewer than k people, and the absence of a key never
 * singles out a small remainder. See docs/architecture/engagement-communication.md §3.
 */
final class GroupKeys
{
    public const POSITION_COLUMNS = ['company' => 'company_id', 'location' => 'location_id', 'department' => 'department_id', 'business_unit' => 'business_unit_id', 'grade' => 'grade_id'];

    public function minimum(): int
    {
        return max(2, (int) config('peopleos.engagement.analytics_min_group', 5));
    }

    /**
     * @param  list<int>  $employeeIds  the eligible population
     * @return array<int, string|null> employee id => group key
     */
    public function assign(?string $dimension, array $employeeIds, string $day): array
    {
        $keys = array_fill_keys($employeeIds, null);
        if ($dimension === null || $employeeIds === []) {
            return $keys;
        }

        $values = $this->values($dimension, $employeeIds, $day);
        $k = $this->minimum();
        $counts = [];
        foreach ($employeeIds as $id) {
            $value = $values[$id] ?? null;
            if ($value !== null) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }
        $named = array_filter($counts, fn (int $n) => $n >= $k);
        asort($named);
        $rest = count($employeeIds) - array_sum($named);
        if ($rest > 0 && $rest < $k && $named !== []) {
            $smallest = array_key_first($named);
            $rest += $named[$smallest];
            unset($named[$smallest]);
        }
        if (count($named) + ($rest > 0 ? 1 : 0) < 2) {
            return $keys;
        }
        foreach ($employeeIds as $id) {
            $value = $values[$id] ?? null;
            $keys[$id] = $value !== null && isset($named[$value]) ? "{$dimension}:{$value}" : 'other';
        }

        return $keys;
    }

    /** @return array<int, int> employee id => dimension value on the day */
    private function values(string $dimension, array $employeeIds, string $day): array
    {
        return AccessScope::withoutScoping(function () use ($dimension, $employeeIds, $day) {
            $out = [];
            foreach (array_chunk($employeeIds, 1000) as $chunk) {
                $rows = $dimension === 'manager'
                    ? ReportingRelationship::query()->effectiveOn($day)->whereIn('employee_id', $chunk)
                        ->whereIn('type', config('peopleos.performance.manager_relationship_types', ['line']))
                        ->orderBy('is_primary')->orderBy('id')->get(['employee_id', 'manager_id as value'])
                    : EmployeePosition::query()->effectiveOn($day)->whereIn('employee_id', $chunk)
                        ->orderBy('effective_from')->orderBy('id')->get(['employee_id', self::POSITION_COLUMNS[$dimension].' as value']);
                foreach ($rows as $row) {
                    if ($row->value !== null) {
                        $out[(int) $row->employee_id] = (int) $row->value;
                    }
                }
            }

            return $out;
        });
    }

    /** Human label for a group key ("Department: Finance", "Team of A. Kumar", "Other groups"). */
    public function label(?string $key): string
    {
        if ($key === null) {
            return 'All respondents';
        }
        if ($key === 'other') {
            return 'Other groups (combined)';
        }
        [$dimension, $id] = explode(':', $key, 2) + [null, null];

        return AccessScope::withoutScoping(fn () => match ($dimension) {
            'company' => 'Company: '.(Company::query()->whereKey($id)->value('name') ?? $id),
            'location' => 'Location: '.(Location::query()->whereKey($id)->value('name') ?? $id),
            'department' => 'Department: '.(Department::query()->whereKey($id)->value('name') ?? $id),
            'business_unit' => 'Business unit: '.(BusinessUnit::query()->whereKey($id)->value('name') ?? $id),
            'grade' => 'Grade: '.(Grade::query()->whereKey($id)->value('name') ?? $id),
            'manager' => 'Team of '.(Employee::query()->with('person')->find($id)?->person?->full_name ?? 'a manager'),
            default => $key,
        });
    }
}
