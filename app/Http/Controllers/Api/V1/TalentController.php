<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Models\TalentReviewSession;
use App\Domain\Talent\Services\TalentAnalytics;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9 talent API (`/api/v1/talent/*`, scope talent.read). Pools, memberships (who, which pool,
 * when — not the reason), review sessions with decision counts, and suppressed analytics. Never
 * returned: talent assessments or ratings, confidential notes, membership or decision reasons,
 * review summaries. Ids from another tenant resolve to 404.
 */
class TalentController extends Controller
{
    use PaginatesApi;

    public function pools(Request $request): JsonResponse
    {
        return $this->page(TalentPool::query()->withCount(['memberships as active_members' => fn ($q) => $q->where('status', 'active')])->orderBy('code'), $request, fn (TalentPool $p) => [
            'code' => $p->code, 'name' => $p->name, 'status' => $p->status, 'active_members' => $p->active_members,
        ]);
    }

    public function memberships(Request $request): JsonResponse
    {
        $query = TalentPoolMembership::query()->with(['employee:id,employee_code', 'pool:id,code'])
            ->when($request->query('pool_code'), fn (Builder $q, $c) => $q->whereHas('pool', fn ($p) => $p->where('code', $c)))
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))->orderByDesc('id');

        return $this->page($query, $request, fn (TalentPoolMembership $m) => [
            'id' => $m->id, 'pool_code' => $m->pool?->code, 'employee_code' => $m->employee?->employee_code, 'status' => $m->status,
            'effective_from' => $m->effective_from?->toDateString(), 'effective_to' => $m->effective_to?->toDateString(),
        ]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $query = TalentReviewSession::query()->withCount(['items', 'items as decided_items' => fn ($q) => $q->where('status', 'decided')])->orderByDesc('id');

        return $this->page($query, $request, fn (TalentReviewSession $s) => [
            'id' => $s->id, 'name' => $s->name, 'status' => $s->status, 'scheduled_for' => $s->scheduled_for?->toDateString(),
            'employees' => $s->items_count, 'decided' => $s->decided_items, 'completed_at' => $s->completed_at?->toIso8601String(),
        ]);
    }

    public function review(int $review): JsonResponse
    {
        $session = TalentReviewSession::query()->withCount(['items', 'items as decided_items' => fn ($q) => $q->where('status', 'decided')])->findOrFail($review);

        return response()->json(['data' => [
            'id' => $session->id, 'name' => $session->name, 'status' => $session->status, 'scheduled_for' => $session->scheduled_for?->toDateString(),
            'employees' => $session->items_count, 'decided' => $session->decided_items, 'completed_at' => $session->completed_at?->toIso8601String(),
        ]]);
    }

    public function analytics(TalentAnalytics $analytics): JsonResponse
    {
        $summary = $analytics->summary();

        return response()->json(['data' => array_intersect_key($summary, array_flip(['min_group', 'pools', 'review_decisions', 'development_actions', 'aspirations', 'mobility_interest']))]);
    }
}
