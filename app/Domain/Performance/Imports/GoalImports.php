<?php

namespace App\Domain\Performance\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Imports\Concerns\ReadsPerformanceRows;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Performance\Scopes\GoalVisibilityScope;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\GoalSheetRules;

/**
 * Data import DI-35 ("Goals", HROne Import → Performance; matrix 27 §27.10 #3): employees' goals with their cycle (the goal
 * sheet is the employee's goals of a cycle), weight, target and unit, through Goals::create / Goals::update as the goal
 * screens do — the goal sheet policy, field rights, weight limits, the goal period, the unit and the duplicate rule all
 * apply, and only goals the uploader may see are matched (GoalVisibilityScope). A goal is matched on employee + cycle +
 * title: one on file is updated (its definition can no longer change once it is locked for review), any other is created.
 * Employees withdrawn from the cycle are refused; validation adds up the weights of the rows above for each goal sheet.
 */
final class GoalImports extends RowImports
{
    use ReadsPerformanceRows;

    public const TYPE = 'goals';

    /** Statuses a file may give (pending approval comes only from submitting a goal sheet; cancel on the goal's page). */
    private const STATUSES = ['draft', 'active', 'completed'];

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'cycle_code' => ['Cycle code', false], 'title' => ['Goal', true], 'description' => ['Description', false],
            'kra_code' => ['KRA code', false], 'measure_type' => ['Measure', false], 'start_value' => ['Start value', false], 'target_value' => ['Target', false],
            'unit' => ['Unit code', false], 'weight' => ['Weight (%)', false], 'start_date' => ['Start date', false], 'due_date' => ['Due date', false], 'status' => ['Status', false],
        ];
    }

    public function label(): string
    {
        return 'Goal upload';
    }

    protected function module(): string
    {
        return 'performance';
    }

    protected function entityType(): string
    {
        return Goal::class;
    }

    protected function aliases(): array
    {
        return ['cycle' => 'cycle_code', 'performance_cycle' => 'cycle_code', 'goal_name' => 'title', 'goal_title' => 'title', 'kra' => 'kra_code', 'measure' => 'measure_type',
            'target' => 'target_value', 'uom' => 'unit', 'unit_of_measurement' => 'unit', 'weightage' => 'weight', 'end_date' => 'due_date'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => $this->uploader($import), 'sheets' => [],
            'kras' => Kra::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all(),
            'units' => PerformanceUnit::query()->where('status', 'active')->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all()];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $cycle = $this->cycleOf($r['cycle_code'] ?? null, $context, $errors, required: false);
        $attributes = $this->attributes($r, $context, $errors);
        if ($errors !== [] || $employeeId === null || $context['actor'] === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $key = $employeeId.'|'.($cycle?->id ?? '-').'|'.mb_strtolower(trim($r['title']));
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same goal is in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;
        if ($cycle !== null) {
            $appraisal = $this->appraisalOf($cycle, $employeeId);
            if ($appraisal?->isWithdrawn()) {
                return ['action' => 'error', 'errors' => ['The employee was withdrawn from the cycle '.$cycle->name.'.'], 'employee_id' => $employeeId];
            }
            if ($cycle->status === 'closed' || $cycle->status === 'archived') {
                return ['action' => 'error', 'errors' => ["The cycle {$cycle->name} is closed."], 'employee_id' => $employeeId];
            }
        }

        $existing = $this->goalOf($employeeId, $cycle, $r['title']);
        if ($existing !== null && $existing->is_locked) {
            $locked = array_intersect(array_keys($this->changes($existing, $attributes)), Goal::LOCKED_FIELDS);
            if ($locked !== []) {
                return ['action' => 'error', 'errors' => ['This goal is locked for review; its definition cannot change.'], 'employee_id' => $employeeId];
            }
        }
        // The goal sheet's weight limit, counting the rows above (the service checks it again when saving).
        if ($cycle !== null && isset($attributes['weight']) && ! in_array($attributes['status'] ?? 'active', ['completed'], true)) {
            $employee = Employee::query()->findOrFail($employeeId);
            $required = app(Goals::class)->requiredWeight($cycle->id, $employee);
            $sheet = "{$employeeId}|{$cycle->id}";
            $context['sheets'][$sheet] ??= (float) Goal::query()->withoutGlobalScope(AccessScope::class)->withoutGlobalScope(GoalVisibilityScope::class)->where('employee_id', $employeeId)
                ->where('performance_cycle_id', $cycle->id)->whereIn('status', GoalSheetRules::OPEN_STATUSES)->sum('weight');
            $total = $context['sheets'][$sheet] + (float) $attributes['weight'] - (float) ($existing?->weight ?? 0);
            if ($required !== null && $total > $required + 0.001) {
                return ['action' => 'error', 'errors' => ["Goal weights of this goal sheet would add up to {$total} (with the rows above); the cycle allows {$required}."], 'employee_id' => $employeeId];
            }
            $context['sheets'][$sheet] = $total;
        }
        if ($existing === null) {
            return ['action' => 'create', 'employee_id' => $employeeId];
        }
        $changes = $this->changes($existing, $attributes);

        return $changes === []
            ? ['action' => 'skip', 'match' => ['id' => $existing->getKey(), 'reason' => 'Already on file with these values.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => ['id' => $existing->getKey(), 'changes' => array_keys($changes)], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $errors = [];
        $context = $this->prepare($import);
        $cycle = $this->cycleOf($r['cycle_code'] ?? null, $context, $errors, required: false);
        $attributes = $this->attributes($r, $context, $errors);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }
        $goals = app(Goals::class);
        $existing = $this->goalOf((int) $row->employee_id, $cycle, $r['title']);
        if ($existing !== null) {
            $changes = $this->changes($existing, $attributes);
            if ($changes === []) {
                return 'No change';
            }
            $goals->update($existing, $changes, null, $user, $this->label()." #{$import->getKey()}");

            return 'Updated the goal '.$existing->title;
        }
        $goal = $goals->create($attributes + ['employee_id' => (int) $row->employee_id, 'level' => 'employee', 'performance_cycle_id' => $cycle?->id, 'source' => 'import'], [], $user);

        return 'Created the goal '.$goal->title.($goal->status === 'draft' ? ' (draft)' : '');
    }

    /**
     * The goal attributes of the row (blank cells left out). @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    private function attributes(array $r, array $context, array &$errors): array
    {
        $attributes = [];
        $title = trim((string) ($r['title'] ?? ''));
        $title === '' || mb_strlen($title) > 255 ? $errors[] = 'Goal is required (at most 255 characters).' : $attributes['title'] = $title;
        if (isset($r['description'])) {
            $attributes['description'] = $r['description'];
        }
        if (isset($r['kra_code'])) {
            $kra = $context['kras'][mb_strtolower($r['kra_code'])] ?? null;
            $kra === null ? $errors[] = "KRA code '{$r['kra_code']}' is not in the KRA library." : $attributes['kra_id'] = $kra;
        }
        if (isset($r['measure_type'])) {
            $measure = mb_strtolower(trim($r['measure_type']));
            $types = (array) config('peopleos.performance.measure_types', []);
            $key = array_key_exists($measure, $types) ? $measure : array_search($measure, array_map('mb_strtolower', $types), true);
            $key === false ? $errors[] = 'Measure must be one of: '.implode(', ', array_keys($types)).'.' : $attributes['measure_type'] = $key;
        }
        foreach (['start_value' => 'Start value', 'target_value' => 'Target', 'weight' => 'Weight (%)'] as $field => $label) {
            if (isset($r[$field])) {
                $value = str_replace(',', '', $r[$field]);
                is_numeric($value) && ($field !== 'weight' || ((float) $value >= 0 && (float) $value <= 100)) ? $attributes[$field] = (float) $value
                    : $errors[] = $field === 'weight' ? 'Weight is a number from 0 to 100.' : "{$label} must be a number.";
            }
        }
        if (isset($r['unit'])) {
            $unit = $context['units'][mb_strtolower($r['unit'])] ?? null;
            $unit === null ? $errors[] = "Unit code '{$r['unit']}' is not an active unit of measurement." : $attributes['unit_id'] = $unit;
        }
        foreach (['start_date' => 'Start date', 'due_date' => 'Due date'] as $field => $label) {
            if (isset($r[$field])) {
                $date = $this->date($r[$field]);
                $date === null ? $errors[] = "{$label} must be a date (YYYY-MM-DD)." : $attributes[$field] = $date;
            }
        }
        if (isset($attributes['start_date'], $attributes['due_date']) && $attributes['due_date'] < $attributes['start_date']) {
            $errors[] = 'The due date cannot be before the start date.';
        }
        if (isset($r['status'])) {
            $status = mb_strtolower(trim($r['status']));
            in_array($status, self::STATUSES, true) ? $attributes['status'] = $status : $errors[] = 'Status must be one of: '.implode(', ', self::STATUSES).'.';
        }

        return $attributes;
    }

    /** @return array<string, mixed> the attributes that differ from the goal on file (the title is how it was matched) */
    private function changes(Goal $goal, array $attributes): array
    {
        unset($attributes['title']);

        return array_filter($attributes, function ($value, $column) use ($goal) {
            $stored = $goal->getRawOriginal($column);
            if (is_float($value) || is_int($value)) {
                return $stored === null || abs((float) $stored - (float) $value) > 0.0001;
            }

            return ! str_starts_with((string) $stored, (string) $value) || ($stored !== null && strlen((string) $value) !== 10 && (string) $stored !== (string) $value);
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The goal owner's code.", 'EMP0001'],
                ['Cycle code', 'No', 'The performance cycle (Performance → Cycles); the goal sheet is the employee\'s goals of a cycle. Blank: a goal outside cycles.', 'FY26'],
                ['Goal', 'Yes', 'The goal\'s title; with the employee and cycle it identifies the goal.', 'Grow revenue in the north region'],
                ['Description', 'No', 'What the goal is about.', ''],
                ['KRA code', 'No', 'A KRA of the KRA library (goal bank).', 'KRA-SALES'],
                ['Measure', 'No', 'One of: '.implode(', ', array_keys((array) config('peopleos.performance.measure_types', []))).' (the unit\'s, when blank).', 'percentage'],
                ['Start value', 'No', 'Where the measure starts.', '0'],
                ['Target', 'No', 'The target value.', '15'],
                ['Unit code', 'No', 'A unit of measurement (Performance → Masters).', ''],
                ['Weight (%)', 'No', 'The goal\'s weight on the goal sheet; the open goals of a sheet may not add up to more than the cycle allows.', '30'],
                ['Start date', 'No', 'YYYY-MM-DD.', ''],
                ['Due date', 'No', 'YYYY-MM-DD, not before the start date.', ''],
                ['Status', 'No', 'draft, active (default) or completed. Under a goal sheet policy that maps goals, a new goal is a draft the manager approves.', 'active'],
            ],
            'notes' => [
                'A goal is matched on employee + cycle + goal title: on file it is updated, otherwise created. Only goals you may see are matched.',
                'The goal sheet policy applies as on the goal screens: mandatory fields, KRA weightage limits, the number of KRAs, the goal period.',
                'A goal locked for review keeps its definition (title, measure, values, weight, cycle). Employees withdrawn from the cycle are refused.',
                'Submit goal sheets for approval on the goal screens; the upload does not submit them.',
            ],
        ];
    }

    protected function examples(): array
    {
        $codes = Employee::query()->orderBy('id')->limit(2)->pluck('employee_code')->all() ?: ['EMP0001'];
        $cycle = (string) (PerformanceCycle::query()->whereIn('status', ['draft', 'scheduled', 'active'])->orderByDesc('id')->value('code') ?? '');

        return [
            [$codes[0], $cycle, 'Grow revenue in the north region', 'New and repeat business', '', 'percentage', '0', '15', '', '30', '', '', 'active'],
            [$codes[1] ?? $codes[0], $cycle, 'Cut the month-end close to three days', '', '', 'numeric', '6', '3', '', '20', '', '', 'active'],
        ];
    }
}
