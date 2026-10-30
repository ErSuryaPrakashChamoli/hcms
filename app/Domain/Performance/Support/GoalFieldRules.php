<?php

namespace App\Domain\Performance\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Services\GoalPlans;
use App\Domain\Performance\Services\GoalSheetRules;
use App\Domain\Performance\Services\PerformanceRelationships;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * HROne setup parity HP-8d (goal sheet policy → Goal creation configuration): per goal field, what the goal creator, the
 * employee, the manager and the approver (while the goal sheet waits for approval) may do — hidden, view only, view and
 * edit, mandatory. A user holding several roles gets the widest right. Blank rights, no policy, organisation goals,
 * performance administrators and system runs are not limited (as before). The goal form hides, disables and requires
 * fields from it; Goals::create/update check the same rules again (assert).
 */
final class GoalFieldRules
{
    /** Goal field => the goal attributes it covers (key_results: the KPI rows, checked by the form and assertKeyResults). */
    public const FIELDS = [
        'title' => ['title'], 'description' => ['description'], 'kra' => ['kra_id'], 'perspective' => ['perspective_id'], 'measure_type' => ['measure_type'],
        'unit' => ['unit_id', 'unit'], 'start_value' => ['start_value'], 'target_value' => ['target_value'], 'weight' => ['weight'],
        'start_date' => ['start_date'], 'due_date' => ['due_date'], 'key_results' => [],
    ];

    /** What a new goal holds when nobody sets the field (a field one may not edit may still arrive with it). */
    private const DEFAULTS = ['measure_type' => 'percentage', 'start_value' => 0, 'target_value' => 100, 'weight' => 0];

    private const RANK = ['hidden' => 0, 'view' => 1, 'edit' => 2, 'required' => 3];

    /** @param  array<string, string>  $rights  field => hidden | view | edit | required (fields not listed: edit) */
    private function __construct(private readonly array $rights) {}

    public static function unrestricted(): self
    {
        return new self([]);
    }

    /** The rights of $user on a goal of $owner ($goal null = a new goal the user creates). */
    public static function for(?Employee $owner, ?User $user, ?Goal $goal = null): self
    {
        if ($owner === null || $user === null || $user->hasPermission('performance.manage') || ($goal !== null && $goal->level !== 'employee')) {
            return self::unrestricted();
        }
        $rows = app(GoalSheetRules::class)->fieldRules($owner);
        if ($rows === []) {
            return self::unrestricted();
        }
        $relationships = app(PerformanceRelationships::class);
        $roles = array_keys(array_filter([
            'creator' => $goal === null || (int) $goal->created_by === (int) $user->id,
            'employee' => (int) $owner->user_id === (int) $user->id,
            'manager' => $relationships->manages($relationships->forUser($user), $owner->id),
            'approver' => $goal?->status === 'pending_approval' && app(GoalPlans::class)->mayDecideFor($user, $owner),
        ]));
        if ($roles === []) {
            return self::unrestricted();
        }
        $rights = [];
        foreach ($rows as $row) {
            $rank = max(array_map(fn (string $role) => self::RANK[$row[$role] ?? 'edit'] ?? self::RANK['edit'], $roles));
            $rights[(string) $row['field']] = array_search($rank, self::RANK, true);
        }

        return new self($rights);
    }

    public function restricted(): bool
    {
        return $this->rights !== [];
    }

    public function right(string $field): string
    {
        return $this->rights[$field] ?? 'edit';
    }

    public function visible(string $field): bool
    {
        return self::RANK[$this->right($field)] >= self::RANK['view'];
    }

    public function editable(string $field): bool
    {
        return self::RANK[$this->right($field)] >= self::RANK['edit'];
    }

    public function required(string $field): bool
    {
        return $this->right($field) === 'required';
    }

    /**
     * Server-side check of a goal as created or changed: a field the user may not edit keeps its value (on a new goal:
     * stays empty or at its default), and a mandatory field is filled.
     *
     * @param  array<string, mixed>  $attributes  the attributes given
     */
    public function assert(array $attributes, ?Goal $current = null): void
    {
        if (! $this->restricted()) {
            return;
        }
        foreach (self::FIELDS as $field => $columns) {
            $label = self::label($field);
            foreach ($columns as $column) {
                if (! array_key_exists($column, $attributes) || $this->editable($field)) {
                    continue;
                }
                $before = $current === null ? (self::DEFAULTS[$column] ?? null) : $current->getAttribute($column);
                if (self::normalise($attributes[$column]) !== self::normalise($before) && ($current !== null || self::normalise($attributes[$column]) !== '')) {
                    throw new RuntimeException("You cannot change the {$label} of this goal.");
                }
            }
            if ($this->required($field) && $columns !== []) {
                $filled = collect($columns)->contains(fn (string $c) => self::normalise(array_key_exists($c, $attributes) ? $attributes[$c] : $current?->getAttribute($c)) !== ''
                    && ! ($c === 'weight' && (float) (array_key_exists($c, $attributes) ? $attributes[$c] : $current?->getAttribute($c)) <= 0));
                if (! $filled) {
                    throw new RuntimeException(ucfirst($label).' is mandatory.');
                }
            }
        }
    }

    /** KPI rows: unchanged when the user may not edit them, at least one when mandatory. */
    public function assertKeyResults(int $count, bool $changed): void
    {
        if ($changed && ! $this->editable('key_results')) {
            throw new RuntimeException('You cannot change the KPIs of this goal.');
        }
        if ($this->required('key_results') && $count === 0) {
            throw new RuntimeException('KPIs are mandatory.');
        }
    }

    public static function label(string $field): string
    {
        return match ($field) {
            'kra' => 'KRA', 'unit' => mb_strtolower(PerformanceLabels::get('uom')), 'target_value' => mb_strtolower(PerformanceLabels::get('target', 'target')),
            'weight' => 'weightage', 'key_results' => 'KPIs', 'measure_type' => 'measure', default => str_replace('_', ' ', $field),
        };
    }

    private static function normalise(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return Carbon::parse($value)->toDateString();
        }

        return trim((string) $value);
    }
}
