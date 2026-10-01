<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Talent\Services\TalentAnalytics;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9 succession API (`/api/v1/succession/*`, scope succession.read). Critical positions with
 * their current criticality, plans, successors (who, on which plan, status) and readiness labels.
 * Never returned: confidential notes, strengths / gaps, readiness reasons or evidence, closure or
 * removal reasons, assessment reasons. Ids from another tenant resolve to 404.
 */
class SuccessionController extends Controller
{
    use PaginatesApi;

    public function positions(Request $request): JsonResponse
    {
        $query = CriticalPosition::query()->with(['designation:id,code', 'currentAssessment'])->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))->orderBy('id');

        return $this->page($query, $request, fn (CriticalPosition $p) => $this->positionRow($p));
    }

    public function position(int $position): JsonResponse
    {
        return response()->json(['data' => $this->positionRow(CriticalPosition::query()->with(['designation:id,code', 'currentAssessment'])->findOrFail($position))]);
    }

    private function positionRow(CriticalPosition $position): array
    {
        $a = $position->currentAssessment;

        return [
            'id' => $position->id, 'title' => $position->title, 'designation_code' => $position->designation?->code, 'organisation_node_id' => $position->organisation_node_id,
            'status' => $position->status, 'criticality' => $a?->criticality, 'business_impact' => $a?->business_impact, 'scarcity' => $a?->scarcity,
            'replacement_difficulty' => $a?->replacement_difficulty, 'operational_dependency' => $a?->operational_dependency,
            'review_frequency_months' => $position->review_frequency_months, 'next_review_on' => $position->next_review_on?->toDateString(),
            'effective_from' => $position->effective_from?->toDateString(), 'effective_to' => $position->effective_to?->toDateString(),
        ];
    }

    public function plans(Request $request): JsonResponse
    {
        $query = SuccessionPlan::query()->withCount(['successors as active_successors' => fn ($q) => $q->where('status', 'active')])
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))->orderByDesc('id');

        return $this->page($query, $request, fn (SuccessionPlan $p) => $this->planRow($p));
    }

    public function plan(int $plan): JsonResponse
    {
        return response()->json(['data' => $this->planRow(SuccessionPlan::query()->withCount(['successors as active_successors' => fn ($q) => $q->where('status', 'active')])->findOrFail($plan))]);
    }

    private function planRow(SuccessionPlan $plan): array
    {
        return [
            'id' => $plan->id, 'critical_position_id' => $plan->critical_position_id, 'status' => $plan->status, 'vacancy_risk' => $plan->vacancy_risk,
            'review_date' => $plan->review_date?->toDateString(), 'active_successors' => $plan->active_successors, 'closed_at' => $plan->closed_at?->toIso8601String(),
        ];
    }

    public function successors(Request $request): JsonResponse
    {
        $query = Successor::query()->with('employee:id,employee_code')
            ->when($request->query('plan_id'), fn (Builder $q, $id) => $q->where('succession_plan_id', (int) $id))
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->where('status', $request->query('status', 'active'))->orderBy('id');

        return $this->page($query, $request, fn (Successor $s) => [
            'id' => $s->id, 'plan_id' => $s->succession_plan_id, 'employee_code' => $s->employee?->employee_code, 'status' => $s->status,
            'added_at' => $s->added_at?->toIso8601String(), 'removed_at' => $s->removed_at?->toIso8601String(),
        ]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $query = ReadinessAssessment::query()->with(['employee:id,employee_code', 'designation:id,code'])
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->when($request->query('critical_position_id'), fn (Builder $q, $id) => $q->where('critical_position_id', (int) $id))
            ->where('status', $request->query('status', 'current'))->orderByDesc('id');

        return $this->page($query, $request, fn (ReadinessAssessment $r) => [
            'id' => $r->id, 'employee_code' => $r->employee?->employee_code, 'critical_position_id' => $r->critical_position_id, 'designation_code' => $r->designation?->code,
            'level' => $r->readiness_level, 'status' => $r->status, 'effective_from' => $r->effective_from?->toDateString(), 'effective_to' => $r->effective_to?->toDateString(),
        ]);
    }

    public function analytics(TalentAnalytics $analytics): JsonResponse
    {
        $summary = $analytics->summary();

        return response()->json(['data' => [...array_intersect_key($summary, array_flip(['min_group', 'critical_positions', 'succession', 'readiness'])), 'coverage' => $analytics->coverage()]]);
    }
}
