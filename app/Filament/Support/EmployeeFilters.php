<?php

namespace App\Filament\Support;

use App\Domain\Exit\Models\ExitCase;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * HROne parity WO-2: one employee search and one set of employee filters for every workforce register.
 *
 * Every helper takes the relation from the register's record to its employee (`employee` on exit cases, reviews,
 * plans …; null on the Employees register itself), so the same search and filters narrow any register. They only narrow
 * the register's own query: the access scope (AccessScope on employees) still applies inside every sub-query.
 *
 * - search(): employee code, first / last / preferred name, work e-mail and current designation.
 * - codes(): a pasted list of employee codes to keep, and one to leave out (comma, space or new line; at most
 *   `peopleos.bulk.pasted_codes_max` each — the rest is ignored and said so on the filter chip).
 * - organisation(): company, business unit, division, department (with its sub-departments), designation, grade, level,
 *   location — each a multi-select on the employee's current position.
 * - dateRange(): from / until on a date column of the register (or of the employee).
 * - excludePendingResignation(): leaves out employees with an open exit case.
 * - backgroundCheck(): employees with no background check, one in progress, or completed clear / with a discrepancy.
 */
final class EmployeeFilters
{
    /** The position dimensions the organisation group offers (column => [label, model]). */
    public const ORGANISATION = [
        'company_id' => ['Company', Company::class],
        'business_unit_id' => ['Business unit', BusinessUnit::class],
        'division_id' => ['Division', Division::class],
        'department_id' => ['Department (with its sub-departments)', Department::class],
        'designation_id' => ['Designation', Designation::class],
        'grade_id' => ['Grade', Grade::class],
        'level_id' => ['Level', Level::class],
        'location_id' => ['Location', Location::class],
    ];

    /** For a column's searchable(query: …): code, names, work e-mail, designation. */
    public static function search(?string $relation = null): Closure
    {
        return function (Builder $query, string $search) use ($relation): Builder {
            $term = '%'.addcslashes(trim($search), '%_\\').'%';
            $match = fn (Builder $employees) => $employees->where(fn (Builder $q) => $q
                ->where($employees->getModel()->qualifyColumn('employee_code'), 'like', $term)
                ->orWhere($employees->getModel()->qualifyColumn('work_email'), 'like', $term)
                ->orWhereHas('person', fn (Builder $p) => $p->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('preferred_name', 'like', $term))
                ->orWhereHas('currentPosition.designation', fn (Builder $d) => $d->where('name', 'like', $term)));

            return $relation === null ? $match($query) : $query->whereHas($relation, $match);
        };
    }

    /** Apply a constraint on the employee, directly or through the register's relation to it. */
    public static function onEmployee(Builder $query, ?string $relation, Closure $constraint): Builder
    {
        return $relation === null ? $constraint($query) : $query->whereHas($relation, $constraint);
    }

    /** @return list<string> codes from a pasted list (comma, semicolon, space or new line), unique, capped */
    public static function parseCodes(?string $pasted): array
    {
        $codes = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $pasted) ?: []), fn ($c) => $c !== '' && mb_strlen($c) <= 64)));

        return array_slice($codes, 0, self::codesMax());
    }

    public static function codesMax(): int
    {
        return max(1, (int) config('peopleos.bulk.pasted_codes_max', 1000));
    }

    public static function codes(?string $relation = null): Filter
    {
        $count = fn (?string $pasted) => count(array_filter(preg_split('/[\s,;]+/', (string) $pasted) ?: [], fn ($c) => trim($c) !== ''));

        return Filter::make('employee_codes')
            ->schema([
                Textarea::make('codes')->label('Employee codes')->rows(2)->placeholder('e.g. EMP001, EMP002')
                    ->helperText('Paste up to '.self::codesMax().' codes, separated by commas, spaces or new lines.'),
                Textarea::make('exclude_codes')->label('Leave out these employee codes')->rows(2)->placeholder('e.g. EMP003'),
            ])
            ->query(function (Builder $query, array $data) use ($relation): Builder {
                $include = self::parseCodes($data['codes'] ?? null);
                $exclude = self::parseCodes($data['exclude_codes'] ?? null);

                return $query
                    ->when($include !== [], fn (Builder $q) => self::onEmployee($q, $relation, fn (Builder $e) => $e->whereIn($e->getModel()->qualifyColumn('employee_code'), $include)))
                    ->when($exclude !== [], fn (Builder $q) => $relation === null
                        ? $q->whereNotIn($q->getModel()->qualifyColumn('employee_code'), $exclude)
                        : $q->whereDoesntHave($relation, fn (Builder $e) => $e->whereIn($e->getModel()->qualifyColumn('employee_code'), $exclude)));
            })
            ->indicateUsing(function (array $data) use ($count): array {
                $indicators = [];
                foreach (['codes' => 'Codes', 'exclude_codes' => 'Leaving out'] as $field => $label) {
                    $n = $count($data[$field] ?? null);
                    if ($n > 0) {
                        $indicators[] = Indicator::make($label.': '.min($n, self::codesMax()).($n > self::codesMax() ? ' (first '.self::codesMax().' of '.$n.')' : ''))->removeField($field);
                    }
                }

                return $indicators;
            });
    }

    /** @param  list<string>|null  $dimensions  columns of ORGANISATION (all when null) */
    public static function organisation(?string $relation = null, ?array $dimensions = null, string $name = 'organisation'): Filter
    {
        $dimensions = array_intersect_key(self::ORGANISATION, array_flip($dimensions ?? array_keys(self::ORGANISATION)));

        return Filter::make($name)
            ->label('Organisation')
            ->columns(2)
            ->schema(array_values(array_map(fn (string $column) => Select::make($column)->label($dimensions[$column][0])->multiple()->searchable()->preload()
                ->options(fn () => $dimensions[$column][1]::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all()), array_keys($dimensions))))
            ->query(function (Builder $query, array $data) use ($relation, $dimensions): Builder {
                $chosen = [];
                foreach (array_keys($dimensions) as $column) {
                    $ids = array_values(array_filter(array_map('intval', (array) ($data[$column] ?? []))));
                    if ($ids !== []) {
                        $chosen[$column] = $column === 'department_id' ? self::withSubDepartments($ids) : $ids;
                    }
                }
                if ($chosen === []) {
                    return $query;
                }

                return self::onEmployee($query, $relation, fn (Builder $e) => $e->whereHas('currentPosition', function (Builder $p) use ($chosen) {
                    foreach ($chosen as $column => $ids) {
                        $p->whereIn($p->getModel()->qualifyColumn($column), $ids);
                    }
                }));
            })
            ->indicateUsing(function (array $data) use ($dimensions): array {
                $indicators = [];
                foreach ($dimensions as $column => [$label, $model]) {
                    $ids = array_values(array_filter((array) ($data[$column] ?? [])));
                    if ($ids !== []) {
                        $names = $model::query()->whereKey($ids)->orderBy('name')->limit(3)->pluck('name')->all();
                        $indicators[] = Indicator::make(str_replace(' (with its sub-departments)', '', $label).': '.implode(', ', $names).(count($ids) > 3 ? ' +'.(count($ids) - 3) : ''))->removeField($column);
                    }
                }

                return $indicators;
            });
    }

    /**
     * @param  list<int>  $ids
     * @return list<int> the departments and every department below them
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

    /** From / until on a date column: the register's own (`$relation` null) or the employee's (`$onEmployee`). */
    public static function dateRange(string $name, string $label, string $column, ?string $relation = null, bool $onEmployee = false): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->columns(2)
            ->schema([
                DatePicker::make('from')->label($label.' from')->native(false),
                DatePicker::make('until')->label($label.' until')->native(false),
            ])
            ->query(function (Builder $query, array $data) use ($column, $relation, $onEmployee): Builder {
                $from = filled($data['from'] ?? null) ? Carbon::parse($data['from'])->startOfDay() : null;
                $until = filled($data['until'] ?? null) ? Carbon::parse($data['until'])->endOfDay() : null;
                if ($from === null && $until === null) {
                    return $query;
                }
                $apply = fn (Builder $q) => $q
                    ->when($from, fn (Builder $w) => $w->where($w->getModel()->qualifyColumn($column), '>=', $from->toDateTimeString()))
                    ->when($until, fn (Builder $w) => $w->where($w->getModel()->qualifyColumn($column), '<=', $until->toDateTimeString()));

                return $onEmployee ? self::onEmployee($query, $relation, $apply) : $apply($query);
            })
            ->indicateUsing(function (array $data) use ($label): array {
                $parts = array_filter([
                    filled($data['from'] ?? null) ? 'from '.Carbon::parse($data['from'])->format('j M Y') : null,
                    filled($data['until'] ?? null) ? 'until '.Carbon::parse($data['until'])->format('j M Y') : null,
                ]);

                return $parts === [] ? [] : [Indicator::make($label.' '.implode(' ', $parts))];
            });
    }

    /**
     * HROne parity TX-1 (matrix 24 §24.1 #8): the employee's status (lifecycle state), employment type and joining date,
     * on registers whose records belong to an employee.
     */
    public static function workforce(?string $relation = null, string $name = 'workforce'): Filter
    {
        return Filter::make($name)
            ->label('Employee status, type and joining')
            ->columns(2)
            ->schema([
                Select::make('lifecycle_state')->label('Employee status')->multiple()->options(collect(LifecycleState::cases())->mapWithKeys(fn (LifecycleState $s) => [$s->value => $s->getLabel()])->all()),
                Select::make('employment_type_id')->label('Employment type')->multiple()->preload()
                    ->options(fn () => EmploymentType::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all()),
                DatePicker::make('joined_from')->label('Joined from')->native(false),
                DatePicker::make('joined_until')->label('Joined until')->native(false),
            ])
            ->query(function (Builder $query, array $data) use ($relation): Builder {
                $states = array_values(array_filter((array) ($data['lifecycle_state'] ?? []), fn ($v) => is_string($v) && LifecycleState::tryFrom($v) !== null));
                $types = array_values(array_filter(array_map('intval', (array) ($data['employment_type_id'] ?? []))));
                $from = filled($data['joined_from'] ?? null) ? Carbon::parse($data['joined_from'])->toDateString() : null;
                $until = filled($data['joined_until'] ?? null) ? Carbon::parse($data['joined_until'])->toDateString() : null;
                if ($states === [] && $types === [] && $from === null && $until === null) {
                    return $query;
                }

                return self::onEmployee($query, $relation, fn (Builder $e) => $e
                    ->when($states !== [], fn (Builder $q) => $q->whereIn($q->getModel()->qualifyColumn('lifecycle_state'), $states))
                    ->when($types !== [], fn (Builder $q) => $q->whereHas('currentPosition', fn (Builder $p) => $p->whereIn($p->getModel()->qualifyColumn('employment_type_id'), $types)))
                    ->when($from !== null, fn (Builder $q) => $q->whereDate($q->getModel()->qualifyColumn('joining_date'), '>=', $from))
                    ->when($until !== null, fn (Builder $q) => $q->whereDate($q->getModel()->qualifyColumn('joining_date'), '<=', $until)));
            })
            ->indicateUsing(function (array $data): array {
                $indicators = [];
                $states = array_values(array_filter((array) ($data['lifecycle_state'] ?? [])));
                if ($states !== []) {
                    $indicators[] = Indicator::make('Status: '.implode(', ', array_map(fn ($v) => LifecycleState::tryFrom((string) $v)?->getLabel() ?? (string) $v, $states)))->removeField('lifecycle_state');
                }
                $types = array_values(array_filter((array) ($data['employment_type_id'] ?? [])));
                if ($types !== []) {
                    $indicators[] = Indicator::make('Employment type: '.implode(', ', EmploymentType::query()->whereKey($types)->orderBy('name')->limit(3)->pluck('name')->all()).(count($types) > 3 ? ' +'.(count($types) - 3) : ''))->removeField('employment_type_id');
                }
                foreach (['joined_from' => 'Joined from', 'joined_until' => 'Joined until'] as $field => $label) {
                    if (filled($data[$field] ?? null)) {
                        $indicators[] = Indicator::make($label.' '.Carbon::parse($data[$field])->format('j M Y'))->removeField($field);
                    }
                }

                return $indicators;
            });
    }

    /** Leaves out employees with an open exit case (resigned or being exited). */
    public static function excludePendingResignation(?string $relation = null): Filter
    {
        return Filter::make('exclude_pending_resignation')
            ->label('Leave out employees with a pending resignation or exit')
            ->toggle()
            ->query(fn (Builder $query) => self::onEmployee($query, $relation, fn (Builder $e) => $e->whereDoesntHave('exitCases', fn (Builder $x) => $x->whereIn('status', ExitCase::OPEN))));
    }

    /** Background check state of the employee. */
    public static function backgroundCheck(?string $relation = null): SelectFilter
    {
        return SelectFilter::make('background_check')
            ->label('Background check')
            ->options(['none' => 'No background check', 'open' => 'In progress', 'clear' => 'Completed — clear', 'discrepancy' => 'Completed — discrepancy or failed'])
            ->query(function (Builder $query, array $data) use ($relation): Builder {
                $value = $data['value'] ?? null;
                if (blank($value)) {
                    return $query;
                }

                return self::onEmployee($query, $relation, fn (Builder $e) => match ($value) {
                    'none' => $e->whereDoesntHave('bgvCases'),
                    'open' => $e->whereHas('bgvCases', fn (Builder $c) => $c->whereIn('status', ['initiated', 'in_progress'])),
                    'clear' => $e->whereHas('bgvCases', fn (Builder $c) => $c->where('status', 'completed')->where('overall_result', 'clear')),
                    'discrepancy' => $e->whereHas('bgvCases', fn (Builder $c) => $c->whereIn('overall_result', ['discrepancy', 'failed'])),
                    default => $e,
                });
            });
    }

    /**
     * The whole set for a register whose records belong to an employee (search goes on the employee column).
     *
     * @return list<mixed>
     */
    public static function forRegister(string $relation = 'employee', ?string $dateColumn = null, ?string $dateLabel = null, bool $excludeLeavers = true): array
    {
        return array_values(array_filter([
            self::codes($relation),
            self::organisation($relation),
            $dateColumn !== null ? self::dateRange('request_dates', $dateLabel ?? 'Date', $dateColumn) : null,
            $excludeLeavers ? self::excludePendingResignation($relation) : null,
        ]));
    }
}
