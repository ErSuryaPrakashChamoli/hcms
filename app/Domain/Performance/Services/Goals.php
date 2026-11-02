<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\GoalViewer;
use App\Domain\Performance\Models\KeyResult;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Domain\Performance\Scopes\GoalVisibilityScope;
use App\Domain\Performance\Support\GoalFieldRules;
use App\Domain\Performance\Support\GoalVisibility;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Goal cascade, key results and check-ins (§34). Progress rolls up from key results and from child goals. HROne setup
 * parity HP-8d: an employee's goals follow their goal sheet policy (GoalSheetRules: weight and count rules, equal
 * weightage, approval of the submitted sheet; GoalFieldRules: field rights) — without a policy, as before.
 *
 * Owner decision 2026-10-29: on a goal sheet mapped by the system (GoalSheetRules::managerOwned) the reporting manager owns
 * the final version. The employee's own additions and changes are proposals (proposes): they wait in `pending_approval`
 * on the sheet's goal plan until the manager confirms (GoalPlans::propose); the employee cannot make a goal active,
 * complete or cancel it there. The manager (and HR) change the sheet directly and confirm it. Manual goal sheets are
 * unchanged.
 *
 * HROne parity GO-4: organisation goals take an owner person and a visibility (private + visible-to list, D-GO5); any goal
 * takes tags; check-ins carry the goal's health and the period they cover; the actual achievement may override the
 * system's while `performance.goals.achievement_override` is on (D-GO7, overrideAchievement). Roll-ups and alignment
 * checks read goals whatever their visibility (GoalVisibilityScope lifted), so a private goal never changes the numbers.
 */
final class Goals
{
    /** Owner decision 2026-10-29: the refusal when the employee tries to finalise a goal of a system-mapped goal sheet alone. */
    public const MANAGER_OWNED = 'Your reporting manager owns this goal sheet: ask them to complete or cancel the goal.';

    public function __construct(private readonly AuditRecorder $audit, private readonly PerformanceRelationships $relationships, private readonly PerformanceMasters $masters, private readonly GoalSheetRules $sheet) {}

    /**
     * @param  array<int, array<string, mixed>>  $keyResults  key results created with the goal
     * @param  list<array<string, mixed>>|null  $formKeyResults  HP-8d: key results the caller saves itself after the goal
     *                                                           (the goal form), checked here against the goal sheet rules
     */
    public function create(array $attributes, array $keyResults = [], ?User $actor = null, ?array $formKeyResults = null): Goal
    {
        // SaaS.3: shadow entitlement observation (never blocks; see Entitlements).
        app(Entitlements::class)->observe(Capability::Performance, 'performance.goal.create');
        // GO-4: the visible-to list is saved beside the goal; tags are trimmed and de-duplicated.
        $viewers = array_key_exists('visible_to', $attributes) ? (array) $attributes['visible_to'] : null;
        unset($attributes['visible_to']);
        $attributes = $this->withTags($attributes);
        $attributes['level'] ??= isset($attributes['employee_id']) ? 'employee' : 'company';
        $attributes['status'] ??= 'active';
        $attributes['source'] ??= 'manual';
        $attributes['created_by'] ??= $actor?->id ?? auth()->id();

        if (isset($attributes['parent_id'])) {
            $parent = Goal::query()->findOrFail($attributes['parent_id']);
            if (! $parent->isOpen()) {
                throw new RuntimeException('Cannot align to a closed goal.');
            }
        }
        $this->assertOwnership($attributes, $actor ?? auth()->user());
        $this->assertGoalPeriodOpen($attributes['performance_cycle_id'] ?? null, $actor ?? auth()->user(), $attributes['employee_id'] ?? null);
        $attributes = $this->withUnit($attributes);
        $this->masters->assertGoalReferences($attributes, $actor ?? auth()->user());
        foreach ($keyResults as $kr) {
            if ((int) ($kr['weight'] ?? 0) < 0) {
                throw new RuntimeException('A key result weight cannot be negative.');
            }
        }
        // HP-8d: the goal sheet policy of the goal's owner. Goals a template creates take the template's values (the sheet
        // is checked when it is submitted); with equal weightage the system sets the weights after saving.
        $owner = $this->sheetOwner($attributes);
        // Owner decision 2026-10-29: the employee's addition to a system-mapped goal sheet is a proposal for the manager.
        $proposal = $this->proposes($owner, $attributes['performance_cycle_id'] ?? null, $actor ?? auth()->user());
        if ($proposal) {
            if (in_array($attributes['status'], ['completed', 'cancelled'], true)) {
                throw new RuntimeException(self::MANAGER_OWNED);
            }
            $attributes['status'] = 'draft';
        }
        if ($owner !== null && empty($attributes['goal_template_id'])) {
            $rights = GoalFieldRules::for($owner, $actor ?? auth()->user());
            $rights->assert($attributes);
            $this->sheet->assertGoal($attributes);
            $rows = array_values($formKeyResults ?? $keyResults);
            if ($rows !== [] || $rights->required('key_results')) {
                $rights->assertKeyResults(count($rows), $rows !== []);
                $equal = ! empty($attributes['performance_cycle_id']) && $this->sheet->equalGoalWeights($owner);
                $this->sheet->assertKeyResults($owner, $equal ? null : (float) ($attributes['weight'] ?? 0), $rows);
            }
        }
        if ($owner !== null && ! empty($attributes['performance_cycle_id']) && $this->sheet->equalGoalWeights($owner)) {
            $attributes['weight'] = 0;
        }

        $this->assertOwnerPerson($attributes);

        return DB::transaction(function () use ($attributes, $keyResults, $owner, $proposal, $actor, $viewers) {
            if ($proposal) {
                $this->lockSheet($owner);
            }
            $this->assertNotDuplicate($attributes);
            $this->assertWithinWeightTotal($attributes);
            $goal = Goal::create($attributes);
            if ($viewers !== null) {
                $this->syncViewers($goal, $viewers, $actor ?? auth()->user());
            }

            foreach ($keyResults as $i => $kr) {
                $goal->keyResults()->create($kr + ['sort_order' => ($i + 1) * 10, 'progress' => Goal::progressFor($kr['measure_type'] ?? 'percentage', (float) ($kr['start_value'] ?? 0), (float) ($kr['target_value'] ?? 100), (float) ($kr['current_value'] ?? 0))]);
            }

            $this->recompute($goal);
            if ($owner !== null) {
                $this->rebalanceSheet($goal->refresh(), $owner);
            }
            if ($proposal) {
                app(GoalPlans::class)->propose($owner, (int) $goal->performance_cycle_id, $goal->refresh(), $actor ?? auth()->user());
            }

            if ($goal->employee_id) {
                PerformanceEvent::dispatch('performance.goal.assigned', $goal->employee, $goal, ['title' => $goal->title, 'due' => $goal->due_date?->toDateString()], [$goal->employee_id]);
            }
            PerformanceEvent::dispatch('performance.goal.created', $goal->employee, $goal, ['title' => $goal->publicTitle(), 'level' => $goal->level], []);

            return $goal->refresh();
        });
    }

    /**
     * Phase 7: change a goal's definition. $expectedVersion is the lock_version the editor loaded;
     * a mismatch means someone else changed it first. Progress changes only through checkIn(). Owner decision 2026-10-29:
     * the employee's change to a system-mapped goal sheet (or to its KPIs, $keyResultsChanged: the goal form saves them
     * first) is a proposal — the goal waits in `pending_approval` until the reporting manager confirms it.
     */
    public function update(Goal $goal, array $attributes, ?int $expectedVersion = null, ?User $actor = null, ?string $reason = null, bool $keyResultsChanged = false): Goal
    {
        unset($attributes['current_value'], $attributes['progress'], $attributes['lock_version'], $attributes['tenant_id'], $attributes['created_by']);
        // GO-4: the achievement override changes only through overrideAchievement(), with its reason.
        unset($attributes['achievement_override'], $attributes['achievement_override_reason'], $attributes['achievement_overridden_by'], $attributes['achievement_overridden_at']);
        $viewers = array_key_exists('visible_to', $attributes) ? (array) $attributes['visible_to'] : null;
        unset($attributes['visible_to']);
        $attributes = $this->withTags($attributes);
        $proposal = $this->proposesFor($goal, $actor ?? auth()->user());

        return DB::transaction(function () use ($goal, $attributes, $expectedVersion, $actor, $reason, $proposal, $keyResultsChanged, $viewers) {
            if ($proposal) {
                $this->lockSheet($this->sheetOwner($goal->only(['level', 'employee_id'])));
            }
            $current = Goal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && $current->lock_version !== $expectedVersion) {
                throw new RuntimeException('This goal was changed by someone else. Reload and try again.');
            }
            if ($proposal) {
                // The goal plan sets the status of a proposal; the employee cannot complete or cancel a mapped goal.
                $status = $attributes['status'] ?? $current->status;
                if (! in_array($current->status, GoalSheetRules::OPEN_STATUSES, true) || ($status !== $current->status && ! in_array($status, GoalSheetRules::OPEN_STATUSES, true))) {
                    throw new RuntimeException(self::MANAGER_OWNED);
                }
                unset($attributes['status']);
            }
            $merged = [...$current->only(['level', 'employee_id', 'organisation_node_id', 'performance_cycle_id', 'title', 'weight', 'status']), ...$attributes];
            $this->assertOwnership($merged, $actor ?? auth()->user());
            // GO-4: a goal is aligned only to a goal the person may see; an employee goal keeps no owner person.
            if (! empty($attributes['parent_id']) && (int) $attributes['parent_id'] !== (int) $current->parent_id && ($actor ?? auth()->user()) !== null
                && Goal::query()->withoutGlobalScope(AccessScope::class)->whereKey($attributes['parent_id'])->doesntExist()) {
                throw new RuntimeException('The goal to align to does not exist.');
            }
            if (($merged['level'] ?? null) === 'employee') {
                // An organisation goal turned into an employee goal drops its owner person and becomes public again.
                if ($current->owner_employee_id !== null || array_key_exists('owner_employee_id', $attributes)) {
                    $attributes['owner_employee_id'] = null;
                }
                if ($current->visibility === 'private' || array_key_exists('visibility', $attributes)) {
                    $attributes['visibility'] = 'public';
                }
            }
            $this->assertOwnerPerson([...$merged, ...$attributes]);
            // HP-8b: outside the goal-setting period only performance administrators change a cycle's goals.
            $this->assertGoalPeriodOpen($current->performance_cycle_id, $actor ?? auth()->user(), $current->employee_id);
            if ((int) ($merged['performance_cycle_id'] ?? 0) !== (int) $current->performance_cycle_id) {
                $this->assertGoalPeriodOpen($merged['performance_cycle_id'] ?? null, $actor ?? auth()->user(), $merged['employee_id'] ?? null);
            }
            // The unit's measure and default target apply only when the unit changes.
            $attributes = $this->withUnit($attributes, (int) ($attributes['unit_id'] ?? 0) !== (int) $current->unit_id);
            $unchanged = array_values(array_filter(['perspective_id', 'unit_id', 'kpi_rating_formula_id'], fn ($f) => ! array_key_exists($f, $attributes) || (int) $attributes[$f] === (int) $current->getAttribute($f)));
            $this->masters->assertGoalReferences([...$merged, ...$attributes, 'employee_id' => $merged['employee_id'] ?? null], $actor ?? auth()->user(), $unchanged);
            // HP-8d: field rights and the goal sheet rules of the owner's policy; equal weightage is the system's to set.
            $owner = $this->sheetOwner($merged);
            if ($owner !== null) {
                if (! empty($merged['performance_cycle_id']) && $this->sheet->equalGoalWeights($owner)) {
                    unset($attributes['weight']);
                    $merged['weight'] = $current->weight;
                }
                GoalFieldRules::for($owner, $actor ?? auth()->user(), $current)->assert($attributes, $current);
                $this->sheet->assertGoal([...$current->attributesToArray(), ...$attributes, ...$merged], $current);
            }
            $this->assertNotDuplicate($merged, $current->id);
            $this->assertWithinWeightTotal($merged, $current->id);

            $changed = $keyResultsChanged || array_diff(array_keys($goal->fill($attributes)->getDirty()), ['lock_version', 'updated_at']) !== [];
            $goal->withAuditReason($reason)->update($attributes);
            if ($viewers !== null) {
                $this->syncViewers($goal, $viewers, $actor ?? auth()->user(), $reason);
            }
            if ($owner !== null) {
                $this->rebalanceSheet($goal->refresh(), $owner);
            }
            if ($proposal && $changed && $owner !== null && $goal->performance_cycle_id !== null) {
                app(GoalPlans::class)->propose($owner, (int) $goal->performance_cycle_id, $goal->refresh(), $actor ?? auth()->user());
            }

            return $goal->refresh();
        });
    }

    /**
     * Owner decision 2026-10-29: whether $actor's change to $owner's goal sheet of this cycle is a proposal — the employee
     * themselves on a goal sheet mapped by the system. The reporting manager, HR and system runs change it directly.
     */
    public function proposes(?Employee $owner, int|string|null $cycleId, ?User $actor): bool
    {
        return $owner !== null && $actor !== null && $owner->user_id !== null && (int) $owner->user_id === (int) $actor->id
            && $this->sheet->managerOwned($owner, $cycleId);
    }

    /** Whether the user's changes to this goal are proposals for the reporting manager (screens and Goals). */
    public function proposesFor(Goal $goal, ?User $actor): bool
    {
        if ($actor === null || $goal->level !== 'employee' || $goal->employee_id === null) {
            return false;
        }
        $owner = $goal->relationLoaded('employee') ? $goal->employee : $this->sheetOwner($goal->only(['level', 'employee_id']));

        return $this->proposes($owner, $goal->performance_cycle_id, $actor);
    }

    /** Every change to a goal sheet that may open or join its goal plan takes the employee row first (as GoalPlans does). */
    private function lockSheet(?Employee $owner): void
    {
        if ($owner !== null) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($owner->id)->lockForUpdate()->first();
        }
    }

    /**
     * HROne setup parity HP-8b (performance.goals.edit_only_in_goal_stage): goals of a cycle are created, changed or
     * closed only while the cycle is active, at its goal-setting stage and inside that stage's dates. Performance
     * administrators, system runs (no actor) and goals outside any cycle are not limited; progress check-ins never are.
     *
     * HROne parity GO-2: an extension of the goal-setting window (PerformanceWindows, for everyone or for the goal's
     * owner) moves the end of both checks to its date, and keeps goal setting open for that person until then even once
     * the cycle has moved on to its reviews (HROne "Extend goal").
     */
    public function assertGoalPeriodOpen(int|string|null $cycleId, ?User $actor, int|string|null $employeeId = null): void
    {
        $windows = app(PerformanceWindows::class);
        $owner = empty($employeeId) ? null : (int) $employeeId;
        // HROne setup parity HP-8i: the goal window of the goal owner's performance policy (blank = no window).
        if (! empty($cycleId) && ! empty($employeeId) && $actor !== null && ! $actor->hasPermission('performance.manage')
            && ($cycle = PerformanceCycle::query()->find($cycleId)) !== null
            && ($window = app(PerformancePolicyRules::class)->goalWindow(Employee::query()->withoutGlobalScope(AccessScope::class)->find($employeeId), $cycle)) !== null
            && (today()->lt($window[0]) || (today()->gt($window[1]) && ! (($extended = $windows->extensionEnd($cycle, 'goal_setting', $owner)) !== null && today()->lte($extended))))) {
            throw new RuntimeException("Goals of {$cycle->name} can be changed only during the goal window ({$window[0]->format('d M Y')} – {$window[1]->format('d M Y')}).");
        }
        if (empty($cycleId) || $actor === null || $actor->hasPermission('performance.manage')
            || ! app(SettingsRepository::class)->get('performance.goals.edit_only_in_goal_stage', false)) {
            return;
        }
        $cycle = PerformanceCycle::query()->find($cycleId);
        if ($cycle === null) {
            return;
        }
        $stage = collect($cycle->stages ?? [])->firstWhere('key', 'goal_setting');
        $today = today()->toDateString();
        $started = empty($stage['starts_on']) || $stage['starts_on'] <= $today;
        $end = $windows->stageEnd($cycle, 'goal_setting', $owner);
        $extended = $windows->extensionEnd($cycle, 'goal_setting', $owner);
        $open = $cycle->status === 'active' && $started && ($end === null || $end->toDateString() >= $today)
            && ($cycle->current_stage === 'goal_setting' || ($cycle->isAtOrPast('goal_setting') && $extended !== null && $extended->toDateString() >= $today));
        if (! $open) {
            throw new RuntimeException("Goals of {$cycle->name} can be changed only during its goal-setting period.");
        }
    }

    /**
     * HP-8a: a goal measured in a unit of measurement takes the unit's code as its unit text, and ($defaults) its way of
     * calculating progress and its default target when none was given.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withUnit(array $attributes, bool $defaults = true): array
    {
        if (empty($attributes['unit_id'])) {
            return $attributes;
        }
        $unit = PerformanceUnit::query()->find($attributes['unit_id']);
        if ($unit !== null) {
            $attributes['unit'] = $unit->code;
            if (! $defaults) {
                return $attributes;
            }
            if (empty($attributes['measure_type']) && $unit->measure_type) {
                $attributes['measure_type'] = $unit->measure_type;
            }
            if ((! array_key_exists('target_value', $attributes) || $attributes['target_value'] === null) && $unit->default_target !== null) {
                $attributes['target_value'] = (float) $unit->default_target;
            }
        }

        return $attributes;
    }

    /**
     * The weight total a cycle requires of each employee's goals, or null when not configured. HP-8d: the total KRA
     * weightage of the employee's goal sheet policy comes first, then the template version, then the cycle setting.
     */
    public function requiredWeight(?int $cycleId, ?Employee $employee = null): ?float
    {
        if ($cycleId === null) {
            return null;
        }
        if (($policyTotal = $this->sheet->totalGoalWeight($employee)) !== null) {
            return $policyTotal;
        }
        $cycle = PerformanceCycle::query()->with('templateVersion')->find($cycleId);
        $required = $cycle?->templateVersion?->requiredGoalWeight() ?? $cycle?->setting('required_goal_weight');

        return $required === null ? null : (float) $required;
    }

    /** @return array{total: float, required: ?float, valid: bool, goals: int} */
    public function planStatus(Employee $employee, int $cycleId): array
    {
        $goals = Goal::query()->where('employee_id', $employee->id)->where('performance_cycle_id', $cycleId)->whereIn('status', GoalSheetRules::OPEN_STATUSES);
        $total = (float) (clone $goals)->sum('weight');
        $required = $this->requiredWeight($cycleId, $employee);

        return ['total' => $total, 'required' => $required, 'valid' => (clone $goals)->exists() && ($required === null || abs($total - $required) < 0.001), 'goals' => (clone $goals)->count()];
    }

    /**
     * Submit an employee's goal plan for a cycle: the weights must add up to the configured total. HP-8d: the goal sheet
     * policy's number of KRAs is checked, and when it asks for approval the drafts wait in `pending_approval` on a goal
     * plan (GoalPlans) instead of becoming active; the number returned is then the goals submitted.
     */
    public function submitPlan(Employee $employee, int $cycleId, ?User $actor = null): int
    {
        if ($actor !== null) {
            // Only the employee, their performance manager or HR submits a goal sheet.
            $this->assertOwnership(['level' => 'employee', 'employee_id' => $employee->id], $actor);
        }
        $status = $this->planStatus($employee, $cycleId);
        if ($status['goals'] === 0) {
            throw new RuntimeException('There are no goals to submit.');
        }
        if (! $status['valid']) {
            throw new RuntimeException("Goal weights add up to {$status['total']}; this cycle requires {$status['required']}.");
        }
        $this->sheet->assertPlan($employee, $cycleId);
        // Owner decision 2026-10-29: the employee never finalises a system-mapped goal sheet alone; the manager confirms it.
        if ($this->proposes($employee, $cycleId, $actor ?? auth()->user())) {
            return app(GoalPlans::class)->propose($employee, $cycleId, null, $actor ?? auth()->user())->goals;
        }
        $approval = $this->sheet->planApproval($employee);
        if ($approval !== 'none') {
            return app(GoalPlans::class)->submit($employee, $cycleId, $approval, $status, $actor ?? auth()->user())->goals;
        }

        return DB::transaction(function () use ($employee, $cycleId, $actor, $status) {
            $drafts = Goal::query()->where('employee_id', $employee->id)->where('performance_cycle_id', $cycleId)->where('status', 'draft')->get();
            $drafts->each(fn (Goal $g) => $g->update(['status' => 'active']));
            $this->audit->record(AuditAction::Submitted, 'performance', $employee, [], null, actor: $actor, metadata: ['goal_plan_cycle_id' => $cycleId, 'total_weight' => $status['total'], 'goals' => $status['goals']]);

            return $drafts->count();
        });
    }

    /** Employees own their goals, managers their reports' goals; organisation goals need performance.manage. */
    private function assertOwnership(array $attributes, ?User $actor): void
    {
        if ($actor === null || $actor->hasPermission('performance.manage')) {
            return;
        }
        if (($attributes['level'] ?? 'employee') !== 'employee' || empty($attributes['employee_id'])) {
            throw new RuntimeException('Only performance administrators set organisation goals.');
        }
        $me = $this->relationships->forUser($actor);
        $own = $me !== null && (int) $me->id === (int) $attributes['employee_id'];
        if (! $own && ! ($actor->hasPermission('performance.team') && $this->relationships->manages($me, $attributes['employee_id']))) {
            throw new RuntimeException('You can only set goals for yourself or for employees you manage.');
        }
    }

    private function assertNotDuplicate(array $attributes, ?int $ignoreId = null): void
    {
        if (in_array($attributes['status'] ?? 'active', ['cancelled'], true)) {
            return;
        }
        $duplicate = Goal::query()->withoutGlobalScopes([AccessScope::class, GoalVisibilityScope::class])
            ->where('level', $attributes['level'] ?? 'employee')
            ->where('employee_id', $attributes['employee_id'] ?? null)
            ->where('organisation_node_id', $attributes['organisation_node_id'] ?? null)
            ->where('performance_cycle_id', $attributes['performance_cycle_id'] ?? null)
            ->whereRaw('lower(title) = ?', [mb_strtolower(trim((string) ($attributes['title'] ?? '')))])
            ->where('status', '!=', 'cancelled')
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('A goal with this title already exists for this owner and cycle.');
        }
    }

    private function assertWithinWeightTotal(array $attributes, ?int $ignoreId = null): void
    {
        $required = empty($attributes['employee_id']) ? null : $this->requiredWeight($attributes['performance_cycle_id'] ?? null, $this->sheetOwner($attributes));
        if ($required === null || in_array($attributes['status'] ?? 'active', ['cancelled', 'completed'], true)) {
            return;
        }
        $others = (float) Goal::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $attributes['employee_id'])
            ->where('performance_cycle_id', $attributes['performance_cycle_id'])->whereIn('status', GoalSheetRules::OPEN_STATUSES)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->lockForUpdate()->sum('weight');
        if ($others + (float) ($attributes['weight'] ?? 0) > $required + 0.001) {
            throw new RuntimeException('Goal weights would add up to '.($others + (float) ($attributes['weight'] ?? 0))." ; this cycle allows {$required}.");
        }
    }

    /**
     * GO-4: $health (not started / on track / behind / at risk) becomes the goal's health; $periodStart / $periodEnd are the
     * period the update covers (HROne "Task period"). Without them a check-in is exactly as before.
     */
    public function checkIn(Goal|KeyResult $subject, ?float $value, ?string $note = null, ?string $confidence = null, ?User $actor = null, string $source = 'manual', ?string $measurement = null, ?string $idempotencyKey = null, ?string $health = null, ?string $periodStart = null, ?string $periodEnd = null): GoalCheckIn
    {
        $goalId = $subject instanceof Goal ? $subject->id : $subject->goal_id;
        if ($idempotencyKey !== null && ($previous = GoalCheckIn::query()->where('goal_id', $goalId)->where('idempotency_key', $idempotencyKey)->first())) {
            return $previous; // a retried request: nothing is recorded twice
        }
        if (! array_key_exists($source, config('peopleos.performance.goal_sources'))) {
            throw new RuntimeException("Unknown progress source '{$source}'.");
        }
        if ($confidence !== null && ! array_key_exists($confidence, GoalCheckIn::CONFIDENCE)) {
            throw new RuntimeException("Unknown confidence '{$confidence}'.");
        }
        if ($health !== null && ! array_key_exists($health, (array) config('peopleos.performance.goal_health'))) {
            throw new RuntimeException("Unknown goal status '{$health}'.");
        }
        $periodStart = blank($periodStart) ? null : Carbon::parse($periodStart)->toDateString();
        $periodEnd = blank($periodEnd) ? null : Carbon::parse($periodEnd)->toDateString();
        if ($periodStart !== null && $periodEnd !== null && $periodEnd < $periodStart) {
            throw new RuntimeException('The check-in period cannot end before it starts.');
        }
        $goal = $subject instanceof Goal ? $subject : $subject->goal()->firstOrFail();

        if (! $goal->isOpen()) {
            throw new RuntimeException('This goal is closed.');
        }
        if ($goal->is_locked && $goal->performance_cycle_id && $goal->cycle()->value('status') === 'closed') {
            throw new RuntimeException('This goal belongs to a closed cycle.');
        }

        return DB::transaction(function () use ($subject, $goal, $value, $note, $confidence, $actor, $source, $measurement, $idempotencyKey, $health, $periodStart, $periodEnd) {
            // Serialize concurrent updates of the same goal; read the previous values under the lock.
            Goal::query()->withoutGlobalScope(GoalVisibilityScope::class)->whereKey($goal->id)->lockForUpdate()->first();
            $subject->refresh();
            $previousValue = $subject->current_value;
            $previousProgress = $subject->progress;
            $current = $value ?? (float) $subject->current_value;
            $progress = Goal::progressFor($subject->measure_type, (float) $subject->start_value, (float) $subject->target_value, $current);
            $subject->update(['current_value' => $current, 'progress' => $progress]);

            $checkIn = GoalCheckIn::create([
                'goal_id' => $goal->id,
                'key_result_id' => $subject instanceof KeyResult ? $subject->id : null,
                'previous_value' => $previousValue,
                'value' => $current,
                'previous_progress' => $previousProgress,
                'progress' => $progress,
                'confidence' => $confidence,
                'source' => $source,
                'idempotency_key' => $idempotencyKey,
                'measurement' => $measurement,
                'note' => $note,
                'created_by' => $actor?->id ?? auth()->id(),
                'health' => $health,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            if ($health !== null && $health !== $goal->refresh()->health) {
                $goal->update(['health' => $health]);
            }
            $this->recompute($goal);
            PerformanceEvent::dispatch('performance.goal.progress_updated', $goal->employee, $goal, ['title' => $goal->publicTitle(), 'progress' => (float) $goal->refresh()->progress, 'source' => $source], []);

            return $checkIn;
        });
    }

    /** Weighted average of key results (or children for organisation goals), then bubble up. */
    public function recompute(Goal $goal): void
    {
        $goal->loadMissing('keyResults');
        // GO-4: aligned goals count whatever their visibility to the person whose action triggered the roll-up.
        $sources = $goal->keyResults->isNotEmpty() ? $goal->keyResults : $goal->children()->withoutGlobalScope(GoalVisibilityScope::class)->where('status', '!=', 'cancelled')->get();

        if ($sources->isNotEmpty()) {
            $totalWeight = $sources->sum(fn ($s) => max(0, (int) $s->weight)) ?: $sources->count();
            $progress = $sources->sum(fn ($s) => (float) $s->progress * (max(0, (int) $s->weight) ?: ($sources->sum('weight') > 0 ? 0 : 1))) / $totalWeight;
            $goal->update(['progress' => round($progress, 2)]);
        }

        if ($goal->parent_id) {
            $parent = $goal->parent()->withoutGlobalScope(GoalVisibilityScope::class)->first();
            if ($parent && $parent->keyResults()->doesntExist()) {
                $this->recompute($parent);
            }
        }
    }

    public function close(Goal $goal, string $status, ?string $reason = null): Goal
    {
        if (! $goal->isOpen()) {
            throw new RuntimeException('This goal is already closed.');
        }
        if (! in_array($status, ['completed', 'cancelled'], true)) {
            throw new RuntimeException('Goals close as completed or cancelled.');
        }
        if ($this->proposesFor($goal, auth()->user())) {
            throw new RuntimeException(self::MANAGER_OWNED);
        }
        $this->assertGoalPeriodOpen($goal->performance_cycle_id, auth()->user(), $goal->employee_id);

        $goal->withAuditReason($reason)->update(['status' => $status, 'progress' => $status === 'completed' ? 100 : $goal->progress]);
        $this->audit->record($status === 'completed' ? AuditAction::Update : AuditAction::Cancelled, 'performance', $goal, [['field' => 'status', 'before' => 'active', 'after' => $status]], $reason);

        if ($goal->parent_id && ($parent = $goal->parent()->withoutGlobalScope(GoalVisibilityScope::class)->first()) !== null) {
            $this->recompute($parent);
        }
        // HP-8d: with equal weightage the goals left on the sheet share the total again.
        if (($owner = $this->sheetOwner($goal->only(['level', 'employee_id']))) !== null) {
            $this->rebalanceSheet($goal, $owner);
        }
        // HROne setup parity HP-9e: a completed individual goal may be shown as an achievement on the social wall.
        if ($status === 'completed' && $goal->employee_id !== null) {
            PerformanceEvent::dispatch('performance.goal.completed', $goal->employee, $goal, ['title' => $goal->title], []);
        }

        return $goal;
    }

    /**
     * HP-8d: the KPI rows of a goal as the goal form saves them (its key results are saved by the form after the goal):
     * field rights on KPIs and the goal sheet policy's KPI rules. $goal null = a new goal.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function assertKeyResultRows(?Employee $owner, ?Goal $goal, float $goalWeight, array $rows, ?User $actor = null): void
    {
        if ($owner === null) {
            return;
        }
        $changed = $this->keyResultRowsChanged($goal, $rows);
        GoalFieldRules::for($owner, $actor ?? auth()->user(), $goal)->assertKeyResults(count($rows), $changed);
        if ($changed) {
            $equal = $goal?->performance_cycle_id !== null && $this->sheet->equalGoalWeights($owner);
            $this->sheet->assertKeyResults($owner, $equal ? null : $goalWeight, $rows);
        }
    }

    /**
     * Whether the goal form's KPI rows differ from the goal's key results (title, weight, target, start, measure).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function keyResultRowsChanged(?Goal $goal, array $rows): bool
    {
        $signature = fn (iterable $items) => collect($items)->map(fn ($r) => [trim((string) data_get($r, 'title')), (float) data_get($r, 'weight', 0), (float) data_get($r, 'target_value', 0), (float) data_get($r, 'start_value', 0), (string) data_get($r, 'measure_type', '')])->values()->all();

        return $goal === null ? $rows !== [] : $signature($rows) !== $signature($goal->keyResults()->get());
    }

    /** HP-8d: after the goal form saved the key results: equal KPI weightage, then progress. */
    public function keyResultsSaved(Goal $goal): void
    {
        if ($this->sheet->rebalanceKeyResults($goal) > 0) {
            $goal->unsetRelation('keyResults');
        }
        $this->recompute($goal);
    }

    /** The employee whose goal sheet an employee-level goal is on (null for organisation goals). */
    private function sheetOwner(array $attributes): ?Employee
    {
        if (($attributes['level'] ?? 'employee') !== 'employee' || empty($attributes['employee_id'])) {
            return null;
        }

        return Employee::query()->withoutGlobalScope(AccessScope::class)->find($attributes['employee_id']);
    }

    /** Equal KRA weightage across the sheet, then equal KPI weightage of the goals whose weight moved (or of this goal). */
    private function rebalanceSheet(Goal $goal, Employee $owner): void
    {
        $moved = $this->sheet->rebalanceGoals($owner, $goal->performance_cycle_id, $this->requiredWeight($goal->performance_cycle_id, $owner));
        $goals = $moved > 0 ? $this->sheet->openGoals($owner->id, (int) $goal->performance_cycle_id) : collect([$goal]);
        foreach ($goals as $sheetGoal) {
            if ($this->sheet->rebalanceKeyResults($sheetGoal->refresh()) > 0) {
                $this->recompute($sheetGoal->unsetRelation('keyResults'));
            }
        }
    }

    /**
     * HROne parity GO-2 ("Withdraw from cycle", the safe alternative to HROne's "Delete goals"): the employee's open goals of
     * the cycle (draft, waiting for approval, active) are cancelled with the reason — each audited, nothing deleted; the goals
     * they were aligned to are recomputed. Returns how many were cancelled.
     */
    public function cancelForWithdrawal(int $employeeId, int $cycleId, string $reason, ?User $actor): int
    {
        $goals = Goal::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employeeId)->where('performance_cycle_id', $cycleId)
            ->where('level', 'employee')->whereIn('status', GoalSheetRules::OPEN_STATUSES)->orderBy('id')->get();
        foreach ($goals as $goal) {
            $before = $goal->status;
            $goal->withAuditReason($reason)->update(['status' => 'cancelled']);
            $this->audit->record(AuditAction::Cancelled, 'performance', $goal, [['field' => 'status', 'before' => $before, 'after' => 'cancelled']], $reason, actor: $actor);
            if ($goal->parent_id && ($parent = Goal::query()->withoutGlobalScopes([AccessScope::class, GoalVisibilityScope::class])->find($goal->parent_id)) !== null) {
                $this->recompute($parent);
            }
        }

        return $goals->count();
    }

    /**
     * HROne parity GO-4 (owner decision PER-6 / D-GO7): the actual achievement of a goal, entered by performance HR or the
     * performance manager of the goal's person with a reason, beside the system-calculated achievement (`progress`, which
     * keeps rolling up as before). $actual null clears the override (the system's stands again). Only while
     * `performance.goals.achievement_override` is on; never on one's own goal, a cancelled goal or one of a closed cycle.
     * Audited with the system and final values.
     */
    public function overrideAchievement(Goal $goal, ?float $actual, string $reason, ?User $actor = null): Goal
    {
        $actor ??= auth()->user();
        if (! GoalVisibility::overrideEnabled()) {
            throw new RuntimeException('Overriding the calculated achievement is switched off (Setup → Performance).');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for overriding the achievement.');
        }
        if ($actual !== null && ($actual < 0 || $actual > 1000)) {
            throw new RuntimeException('The actual achievement must be between 0 and 1000 %.');
        }
        if (! GoalVisibility::mayOverride($actor, $goal)) {
            throw new RuntimeException('Only performance HR or the manager of the goal\'s owner may override its achievement, never on their own goal.');
        }

        return DB::transaction(function () use ($goal, $actual, $reason, $actor) {
            $current = Goal::query()->withoutGlobalScope(GoalVisibilityScope::class)->whereKey($goal->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'cancelled') {
                throw new RuntimeException('This goal is cancelled.');
            }
            if ($current->performance_cycle_id && $current->cycle()->value('status') === 'closed') {
                throw new RuntimeException('This goal belongs to a closed cycle.');
            }
            $before = $current->achievement_override === null ? null : (float) $current->achievement_override;
            if ($before === $actual) {
                throw new RuntimeException('The actual achievement is already '.($actual === null ? 'the system\'s' : "{$actual} %").'.');
            }
            $current->withoutAuditing()->update([
                'achievement_override' => $actual,
                'achievement_override_reason' => $actual === null ? null : trim($reason),
                'achievement_overridden_by' => $actual === null ? null : $actor?->id,
                'achievement_overridden_at' => $actual === null ? null : now(),
            ]);
            $this->audit->record(AuditAction::Update, 'performance', $current, [['field' => 'achievement_override', 'before' => $before, 'after' => $actual, 'sensitive' => false]], trim($reason), actor: $actor,
                metadata: ['system_achievement' => $current->systemAchievement(), 'final_achievement' => $current->refresh()->finalAchievement(), 'achievement_override' => $actual === null ? 'cleared' : 'set']);

            return $goal->refresh();
        });
    }

    /**
     * GO-4: replace the visible-to list of a goal (employees in the person's reach), audited as one change of the goal.
     *
     * @param  array<int, int|string>  $employeeIds
     */
    private function syncViewers(Goal $goal, array $employeeIds, ?User $actor, ?string $reason = null): void
    {
        $wanted = collect($employeeIds)->filter(fn ($id) => filled($id))->map(fn ($id) => (int) $id)->unique()->sort()->values();
        if ($wanted->isNotEmpty() && Employee::query()->whereIn('id', $wanted)->count() !== $wanted->count()) {
            throw new RuntimeException('Someone on the visible-to list is not an employee you can choose.');
        }
        $rows = GoalViewer::query()->withoutGlobalScope(AccessScope::class)->where('goal_id', $goal->id)->get();
        $before = $rows->pluck('employee_id')->map(fn ($id) => (int) $id)->sort()->values();
        if ($before->all() === $wanted->all()) {
            return;
        }
        $rows->whereNotIn('employee_id', $wanted->all())->each(fn (GoalViewer $row) => $row->delete());
        $wanted->diff($before)->each(fn (int $id) => GoalViewer::create(['tenant_id' => $goal->tenant_id, 'goal_id' => $goal->id, 'employee_id' => $id, 'created_by' => $actor?->id]));
        $this->audit->record(AuditAction::Update, 'performance', $goal, [['field' => 'visible_to', 'before' => $before->implode(', '), 'after' => $wanted->implode(', '), 'sensitive' => false]], $reason, actor: $actor);
    }

    /** GO-4: tags trimmed, without blanks or repeats (at most 20, each up to 40 characters); none = null. */
    private function withTags(array $attributes): array
    {
        if (! array_key_exists('tags', $attributes)) {
            return $attributes;
        }
        $tags = collect((array) $attributes['tags'])->map(fn ($t) => trim((string) $t))->filter(fn ($t) => $t !== '')
            ->unique(fn ($t) => mb_strtolower($t))->values();
        if ($tags->count() > 20 || $tags->contains(fn ($t) => mb_strlen($t) > 40)) {
            throw new RuntimeException('A goal takes at most 20 tags of up to 40 characters each.');
        }
        $attributes['tags'] = $tags->isEmpty() ? null : $tags->all();

        return $attributes;
    }

    /** GO-4: an owner person only on organisation goals, and one the person may choose. */
    private function assertOwnerPerson(array $attributes): void
    {
        if (empty($attributes['owner_employee_id'])) {
            return;
        }
        if (($attributes['level'] ?? 'employee') === 'employee') {
            throw new RuntimeException('Only organisation goals have an owner person (an employee goal belongs to its employee).');
        }
        if (Employee::query()->whereKey($attributes['owner_employee_id'])->doesntExist()) {
            throw new RuntimeException('The goal owner is not an employee you can choose.');
        }
    }

    /** Lock every active goal of the employees in a cycle so appraisals rate a stable set. */
    public function lockForCycle(int $cycleId, array $employeeIds): int
    {
        return Goal::query()->where('performance_cycle_id', $cycleId)->whereIn('employee_id', $employeeIds)->where('status', 'active')->update(['is_locked' => true]);
    }

    public function forEmployee(Employee $employee, ?int $cycleId = null)
    {
        return Goal::query()->with('keyResults')->where('employee_id', $employee->id)
            ->when($cycleId, fn ($q) => $q->where('performance_cycle_id', $cycleId))
            ->orderByDesc('weight')->orderBy('due_date')->get();
    }
}
