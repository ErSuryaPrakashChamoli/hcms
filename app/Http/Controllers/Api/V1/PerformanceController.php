<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\FeedbackEntry;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\PerformanceAnalytics;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Phase 7 performance API (`/api/v1/performance/*`, scope performance.read; progress writes need
 * performance.write and honour Idempotency-Key). Deliberately never returns: review or feedback
 * text, one-on-one notes (shared or private), check-in answers, calibration notes or history,
 * anonymous feedback authors, PIP reasons / objectives / outcomes, or unfinalized ratings.
 */
class PerformanceController extends Controller
{
    public function cycles(Request $request): JsonResponse
    {
        $query = PerformanceCycle::query()->with('templateVersion')->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))->orderByDesc('period_start');

        return $this->page($query, $request, fn (PerformanceCycle $c) => [
            'code' => $c->code, 'name' => $c->name, 'type' => $c->type, 'status' => $c->status, 'current_stage' => $c->current_stage,
            'period_start' => $c->period_start->toDateString(), 'period_end' => $c->period_end->toDateString(),
            'stages' => $c->stageKeys(), 'template_version' => $c->templateVersion?->version, 'template_checksum' => $c->templateVersion?->checksum,
            'launched_at' => $c->launched_at?->toIso8601String(), 'closed_at' => $c->closed_at?->toIso8601String(),
        ]);
    }

    public function goals(Request $request): JsonResponse
    {
        $query = Goal::query()->with(['employee', 'cycle'])
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->when($request->query('cycle'), fn (Builder $q, $c) => $q->whereHas('cycle', fn ($s) => $s->where('code', $c)))
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))
            ->orderByDesc('id');

        return $this->page($query, $request, fn (Goal $g) => $this->goal($g));
    }

    public function goalProgress(Request $request, int $goal): JsonResponse
    {
        $goal = Goal::query()->findOrFail($goal);
        $query = GoalCheckIn::query()->where('goal_id', $goal->id)->orderByDesc('id');

        return $this->page($query, $request, fn (GoalCheckIn $c) => $this->progress($c));
    }

    public function recordGoalProgress(Request $request, int $goal, Goals $goals): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'numeric'],
            'confidence' => ['nullable', 'in:'.implode(',', array_keys(GoalCheckIn::CONFIDENCE))],
            'measurement' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $request->header('Idempotency-Key');
        if ($key !== null && ($key === '' || strlen($key) > 64)) {
            return response()->json(['message' => 'Idempotency-Key must be 1–64 characters.'], 422);
        }
        $goal = Goal::query()->findOrFail($goal);

        try {
            $checkIn = $goals->checkIn($goal, (float) $data['value'], $data['note'] ?? null, $data['confidence'] ?? null, null, 'api', $data['measurement'] ?? null, $key);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->progress($checkIn) + ['goal' => $this->goal($goal->refresh()->load(['employee', 'cycle']))]], $checkIn->wasRecentlyCreated ? 201 : 200);
    }

    /** Reviews: status for everyone in scope; ratings only once a person has finalized them. */
    public function reviews(Request $request): JsonResponse
    {
        $query = Appraisal::query()->with(['employee', 'cycle'])
            ->when($request->query('cycle'), fn (Builder $q, $c) => $q->whereHas('cycle', fn ($s) => $s->where('code', $c)))
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->orderByDesc('id');

        return $this->page($query, $request, function (Appraisal $a) {
            $final = in_array($a->status, ['finalized', 'acknowledged'], true);

            return [
                'id' => $a->id, 'employee_code' => $a->employee?->employee_code, 'cycle' => $a->cycle?->code, 'status' => $a->status,
                'template_version_id' => $a->performance_template_version_id, 'locked' => $a->isLocked(),
                'final_rating' => $final && $a->final_rating !== null ? (float) $a->final_rating : null, 'final_label' => $final ? $a->final_label : null,
                'finalized_at' => $a->finalized_at?->toIso8601String(), 'acknowledged_at' => $a->acknowledged_at?->toIso8601String(),
            ];
        });
    }

    public function checkIns(Request $request): JsonResponse
    {
        $query = PerformanceCheckIn::query()->with('employee')->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))->orderByDesc('period_date');

        return $this->page($query, $request, fn (PerformanceCheckIn $c) => ['id' => $c->id, 'employee_code' => $c->employee?->employee_code, 'cadence' => $c->cadence, 'period_date' => $c->period_date->toDateString(), 'status' => $c->status, 'goal_updates' => count($c->goal_progress ?? []), 'action_count' => count($c->actions ?? []), 'submitted_at' => $c->submitted_at?->toIso8601String(), 'reviewed_at' => $c->reviewed_at?->toIso8601String()]);
    }

    public function oneOnOnes(Request $request): JsonResponse
    {
        $query = OneOnOne::query()->with(['employee', 'manager'])->orderByDesc('scheduled_at');

        return $this->page($query, $request, fn (OneOnOne $m) => ['id' => $m->id, 'employee_code' => $m->employee?->employee_code, 'manager_code' => $m->manager?->employee_code, 'scheduled_at' => $m->scheduled_at?->toIso8601String(), 'held_at' => $m->held_at?->toIso8601String(), 'status' => $m->status, 'action_items' => count($m->action_items ?? [])]);
    }

    public function feedback(Request $request): JsonResponse
    {
        $query = FeedbackEntry::query()->with(['employee', 'author'])->orderByDesc('id');

        return $this->page($query, $request, fn (FeedbackEntry $f) => [
            'id' => $f->id, 'employee_code' => $f->employee?->employee_code, 'type' => $f->type, 'visibility' => $f->visibility, 'anonymous' => (bool) $f->is_anonymous,
            'author_code' => $f->is_anonymous ? null : $f->author?->employee_code, 'status' => $f->status, 'created_at' => $f->created_at?->toIso8601String(),
        ]);
    }

    public function competencies(Request $request): JsonResponse
    {
        return $this->page(Competency::query()->orderBy('code'), $request, fn (Competency $c) => ['code' => $c->code, 'name' => $c->name, 'category' => $c->category, 'level' => $c->level, 'weight' => $c->weight === null ? null : (float) $c->weight, 'status' => $c->status, 'effective_from' => $c->effective_from?->toDateString(), 'effective_to' => $c->effective_to?->toDateString()]);
    }

    public function pips(Request $request): JsonResponse
    {
        $query = ImprovementPlan::query()->with('employee')->orderByDesc('id');

        return $this->page($query, $request, fn (ImprovementPlan $p) => ['id' => $p->id, 'employee_code' => $p->employee?->employee_code, 'status' => $p->status, 'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(), 'closed_at' => $p->closed_at?->toIso8601String()]);
    }

    public function analytics(Request $request, PerformanceAnalytics $analytics): JsonResponse
    {
        $cycle = $request->query('cycle') ? PerformanceCycle::query()->where('code', $request->query('cycle'))->firstOrFail() : null;

        return response()->json(['data' => $analytics->summary($cycle)]);
    }

    private function goal(Goal $g): array
    {
        return ['id' => $g->id, 'employee_code' => $g->employee?->employee_code, 'cycle' => $g->cycle?->code, 'level' => $g->level, 'type' => $g->type, 'title' => $g->title, 'parent_id' => $g->parent_id, 'measure_type' => $g->measure_type, 'target_value' => (float) $g->target_value, 'current_value' => (float) $g->current_value, 'weight' => $g->weight, 'progress' => (float) $g->progress, 'status' => $g->status, 'locked' => (bool) $g->is_locked, 'due_date' => $g->due_date?->toDateString(), 'version' => $g->lock_version];
    }

    private function progress(GoalCheckIn $c): array
    {
        return ['id' => $c->id, 'goal_id' => $c->goal_id, 'key_result_id' => $c->key_result_id, 'previous_value' => $c->previous_value === null ? null : (float) $c->previous_value, 'value' => (float) $c->value, 'previous_progress' => $c->previous_progress === null ? null : (float) $c->previous_progress, 'progress' => (float) $c->progress, 'confidence' => $c->confidence, 'source' => $c->source, 'measurement' => $c->measurement, 'recorded_at' => $c->created_at?->toIso8601String()];
    }

    private function page(Builder $query, Request $request, callable $map): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => collect($paginator->items())->map($map)->values()->all(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }
}
