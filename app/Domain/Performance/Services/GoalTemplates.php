<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalTemplate;
use App\Domain\Performance\Models\GoalTemplateItem;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceCycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity HP-8e (Goal → Goal template): templates of KRAs and KPIs. assign() creates an employee's goals from
 * a template through Goals::create (so every goal rule applies; drafts, source `system` for the system mapping);
 * assignForCycle() is the goal sheet policy's system mapping, run when a cycle is launched or reaches goal setting; "Use
 * template" on the goals screen assigns one by hand. raiseFeedback() asks the manager for the template's feedback form at
 * the manager review.
 */
final class GoalTemplates
{
    public function __construct(private readonly Goals $goals, private readonly GoalSheetRules $sheet, private readonly AuditRecorder $audit) {}

    /** @return array<string, string> code => name of the active templates (goal sheet policy options) */
    public static function options(): array
    {
        return GoalTemplate::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'code')->all();
    }

    /** "Add from library": a KRA of the library and its suggested KPIs become a KRA of the template. */
    public function addFromLibrary(GoalTemplate $template, Kra $kra, ?User $actor = null): GoalTemplateItem
    {
        if (($actor ??= auth()->user()) !== null && ! $actor->hasPermission('performance.manage')) {
            throw new RuntimeException('You cannot change goal templates.');
        }

        return DB::transaction(function () use ($template, $kra) {
            $item = $template->items()->create([
                'kra_id' => $kra->id, 'title' => $kra->name, 'description' => $kra->description, 'weight' => min(100, (int) $kra->default_weight),
                'perspective_id' => $kra->perspective_id, 'sort_order' => ((int) $template->items()->max('sort_order')) + 10,
            ]);
            foreach (array_values((array) ($kra->kpis ?? [])) as $i => $kpi) {
                if (blank($kpi['name'] ?? null)) {
                    continue;
                }
                $item->kpis()->create([
                    'goal_template_id' => $template->id, 'title' => $kpi['name'], 'target_value' => is_numeric($kpi['target'] ?? null) ? (float) $kpi['target'] : null,
                    'unit_id' => $kpi['unit_id'] ?? null, 'is_reverse' => (bool) ($kpi['is_reverse'] ?? false), 'kpi_rating_formula_id' => $kpi['kpi_rating_formula_id'] ?? null, 'sort_order' => ($i + 1) * 10,
                ]);
            }

            return $item;
        });
    }

    /** The template the system maps to an employee: the goal sheet policy's, else the first active one whose conditions match. */
    public function templateFor(Employee $employee): ?GoalTemplate
    {
        $code = $this->sheet->creation($employee)['template'];
        if (filled($code)) {
            return GoalTemplate::query()->where('status', 'active')->where('code', strtoupper((string) $code))->first();
        }

        return GoalTemplate::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()
            ->first(fn (GoalTemplate $t) => $t->appliesTo($employee));
    }

    /**
     * Create the employee's goals from a template: one goal per KRA (its KPIs as key results), in the cycle when given.
     * $actor null = the system mapping (drafts, source `system`, no ownership checks); a person ("Use template") must be
     * allowed to set the employee's goals. A KRA already on the sheet (same title) is skipped. Returns the goals created.
     *
     * @return Collection<int, Goal>
     */
    public function assign(GoalTemplate $template, Employee $employee, ?PerformanceCycle $cycle = null, ?User $actor = null): Collection
    {
        if ($template->status !== 'active') {
            throw new RuntimeException('This goal template is inactive.');
        }
        $template->loadMissing('goals.kpis.measurementUnit');
        $created = collect();
        foreach ($template->goals as $item) {
            $attributes = [
                'level' => 'employee', 'employee_id' => $employee->id, 'performance_cycle_id' => $cycle?->id, 'goal_template_id' => $template->id,
                'kra_id' => $item->kra_id, 'type' => $item->kra_id ? 'kra' : 'goal', 'title' => $item->title, 'description' => $item->description,
                'measure_type' => $item->measure_type, 'unit_id' => $item->unit_id, 'start_value' => (float) $item->start_value,
                'target_value' => $item->target_value === null ? null : (float) $item->target_value, 'weight' => (int) $item->weight, 'is_reverse' => (bool) $item->is_reverse,
                'perspective_id' => $item->perspective_id, 'kpi_rating_formula_id' => $item->kpi_rating_formula_id,
                'status' => 'draft', 'source' => $actor === null ? 'system' : 'manual',
                'start_date' => $cycle?->period_start?->toDateString() ?? today()->toDateString(), 'due_date' => $cycle?->period_end?->toDateString(),
            ];
            $keyResults = $item->kpis->map(fn (GoalTemplateItem $kpi) => [
                'title' => $kpi->title, 'measure_type' => $kpi->measure_type, 'start_value' => (float) $kpi->start_value, 'target_value' => $kpi->target_value === null ? 100.0 : (float) $kpi->target_value,
                'unit_id' => $kpi->unit_id, 'unit' => $kpi->measurementUnit?->code, 'weight' => (int) $kpi->weight, 'is_reverse' => (bool) $kpi->is_reverse,
            ])->all();
            try {
                $created->push($this->goals->create(array_filter($attributes, fn ($v) => $v !== null), $keyResults, $actor));
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        }

        return $created;
    }

    /**
     * The goal sheet policy's system mapping for a cycle: each employee with an appraisal whose policy maps goals by the
     * system gets the goals of their template once (an employee already holding goals from it in the cycle is left
     * alone). Whoever the policy says fills it in first (the employee, or the reporting manager) is told the goal sheet is
     * ready; the reporting manager confirms it either way (owner decision 2026-10-29, Goals / GoalPlans). A failure for
     * one employee never stops the others or the cycle. Returns the goals created.
     */
    public function assignForCycle(PerformanceCycle $cycle): int
    {
        $total = 0;
        $employees = Employee::query()->withoutGlobalScope(AccessScope::class)->with(['person', 'currentManager'])
            ->whereIn('id', Appraisal::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $cycle->id)->pluck('employee_id'))->orderBy('id')->get();
        foreach ($employees as $employee) {
            try {
                $creation = $this->sheet->creation($employee);
                if ($creation['mode'] !== 'system' || ($template = $this->templateFor($employee)) === null) {
                    continue;
                }
                if (Goal::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('performance_cycle_id', $cycle->id)->where('goal_template_id', $template->id)->exists()) {
                    continue;
                }
                $goals = DB::transaction(fn () => $this->assign($template, $employee, $cycle));
                $total += $goals->count();
                $owner = $creation['owner'] === 'manager' ? $employee->currentManager?->manager_id : $employee->id;
                if ($goals->isNotEmpty()) {
                    PerformanceEvent::dispatch('performance.goal_sheet.assigned', $employee, $goals->first(), ['cycle' => $cycle->name, 'template' => $template->name, 'goals' => $goals->count(), 'for' => $employee->person?->full_name], array_filter([$owner]));
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $total;
    }

    /**
     * The feedback form of the templates an employee's goals came from, asked of the manager at the manager review (one
     * per review; FeedbackRequests ignores a template that is missing or inactive). Returns how many were asked.
     */
    public function raiseFeedback(PerformanceCycle $cycle): int
    {
        $raised = 0;
        $stage = collect($cycle->stages ?? [])->firstWhere('key', 'manager_review');
        $reviews = AppraisalReview::query()->with(['appraisal.employee.person', 'reviewer'])->where('type', 'manager')->where('status', 'pending')
            ->whereIn('appraisal_id', Appraisal::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $cycle->id)->pluck('id'))->get();
        foreach ($reviews as $review) {
            try {
                $employee = $review->appraisal?->employee;
                $formId = $employee === null ? null : GoalTemplate::query()->whereNotNull('feedback_template_id')
                    ->whereIn('id', Goal::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('performance_cycle_id', $cycle->id)->whereNotNull('goal_template_id')->select('goal_template_id'))
                    ->orderBy('id')->value('feedback_template_id');
                if ($formId === null || $review->reviewer?->user_id === null) {
                    continue;
                }
                $name = $employee->person?->full_name ?? $employee->employee_code;
                $asked = app(FeedbackRequests::class)->raise($formId, 'goal_review', $review, $employee, 'manager', (int) $review->reviewer->user_id, null,
                    empty($stage['ends_on']) ? null : Carbon::parse($stage['ends_on']), "Goal sheet feedback for {$name}");
                $raised += $asked !== null ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $raised;
    }
}
