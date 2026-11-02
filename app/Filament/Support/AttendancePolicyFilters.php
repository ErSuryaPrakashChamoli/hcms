<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3 #9, #14: "Attendance policy", "Punch type"): filters by the attendance policy that
 * applies to the record's employee today and by its way of counting check-ins (punch_mode). Policies are assigned by
 * rules, so the employees are resolved when the filter is used (inside the viewer's scope).
 */
final class AttendancePolicyFilters
{
    public static function policy(string $permission, string $relation = 'employee'): SelectFilter
    {
        return SelectFilter::make('attendance_policy')
            ->label('Attendance policy')
            ->options(fn () => Policy::query()->where('type', 'attendance')->orderBy('name')->pluck('name', 'id')->map(fn ($n) => (string) $n)->all() + ['none' => 'No attendance policy'])
            ->query(function (Builder $query, array $data) use ($relation): Builder {
                $value = $data['value'] ?? null;
                if (! filled($value)) {
                    return $query;
                }
                $ids = array_keys(array_filter(self::resolved(), fn (array $r) => $value === 'none' ? $r['policy'] === null : $r['policy'] === (int) $value));

                return self::whereEmployee($query, $relation, $ids);
            })
            ->visible(fn () => auth()->user()?->hasPermission($permission) ?? false);
    }

    public static function punchMode(string $permission, string $relation = 'employee'): SelectFilter
    {
        return SelectFilter::make('punch_mode')
            ->label('Punch type')
            ->options(self::punchModes())
            ->query(function (Builder $query, array $data) use ($relation): Builder {
                $value = $data['value'] ?? null;
                if (! filled($value)) {
                    return $query;
                }

                return self::whereEmployee($query, $relation, array_keys(array_filter(self::resolved(), fn (array $r) => $r['mode'] === (string) $value)));
            })
            ->visible(fn () => auth()->user()?->hasPermission($permission) ?? false);
    }

    /** @return array<string, string> the attendance policy's punch_mode options */
    public static function punchModes(): array
    {
        $find = function (array $fields) use (&$find): ?array {
            foreach ($fields as $field) {
                if (($field['key'] ?? null) === 'punch_mode') {
                    return (array) ($field['options'] ?? []);
                }
                if (is_array($field['fields'] ?? null) && ($found = $find($field['fields'])) !== null) {
                    return $found;
                }
            }

            return null;
        };

        return $find((array) config('peopleos.policies.types.attendance.fields', [])) ?? ['double' => 'Double (in and out)'];
    }

    /** @param  list<int>  $ids */
    private static function whereEmployee(Builder $query, string $relation, array $ids): Builder
    {
        $column = $query->getModel() instanceof Employee ? $query->getModel()->getQualifiedKeyName() : $query->getModel()->qualifyColumn($relation.'_id');

        return $query->whereIn($column, $ids === [] ? [0] : $ids);
    }

    /** @return array<int, array{policy: ?int, mode: string}> */
    private static function resolved(): array
    {
        $resolver = app(PolicyResolver::class);
        $out = [];
        foreach (Employee::query()->employed()->orderBy('id')->lazyById(200) as $employee) {
            $version = $resolver->resolve('attendance', $employee, today());
            $out[(int) $employee->id] = ['policy' => $version?->policy_id !== null ? (int) $version->policy_id : null, 'mode' => (string) ($version?->setting('punch_mode') ?? 'double')];
        }

        return $out;
    }
}
