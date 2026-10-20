<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Workforce\Models\WorkforcePlan;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Models\WorkforceScenario;
use App\Domain\Workforce\Services\WorkforcePlans;
use App\Domain\Workforce\Services\WorkforceSnapshot;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 10 workforce API (`/api/v1/workforce/*`, scope workforce.read). Plans, versions and lines,
 * scenarios with their labelled assumptions, headcount and snapshots on any date. Planned costs only
 * with the workforce.costs scope; never salaries or individual pay. Read-only. Foreign codes → 404.
 */
class WorkforceController extends Controller
{
    use PaginatesApi;

    public function plans(Request $request): JsonResponse
    {
        return $this->page(WorkforcePlan::query()->with(['company:id,code', 'activeVersion'])->withCount('versions')->orderBy('code'), $request, fn (WorkforcePlan $p) => [
            'code' => $p->code, 'name' => $p->name, 'company_code' => $p->company?->code, 'versions' => $p->versions_count,
            'active_version' => $p->activeVersion?->version, 'active_from' => $p->activeVersion?->effective_from?->toDateString(),
        ]);
    }

    public function version(Request $request, string $code, int $version, WorkforcePlans $plans): JsonResponse
    {
        $plan = WorkforcePlan::query()->where('code', strtoupper($code))->firstOrFail();
        $row = WorkforcePlanVersion::query()->with('scenario')->where('workforce_plan_id', $plan->id)->where('version', $version)->firstOrFail();
        $costs = $this->costs($request);

        return response()->json(['data' => [
            'plan' => $plan->code, 'version' => $row->version, 'status' => $row->status, 'period_type' => $row->period_type,
            'period_start' => $row->period_start->toDateString(), 'period_end' => $row->period_end->toDateString(), 'currency' => $row->currency,
            'scenario' => $row->scenario?->code, 'effective_from' => $row->effective_from?->toDateString(),
            'net_headcount' => $plans->totals($row)['headcount'], 'net_fte' => $plans->totals($row)['fte'],
            'lines' => $row->lines()->with(['designation:id,code', 'position:id,code'])->orderBy('effective_date')->get()->map(fn (WorkforcePlanLine $l) => [
                'movement' => $l->movement_type, 'position_code' => $l->position?->code, 'designation_code' => $l->designation?->code, 'organisation_node_id' => $l->organisation_node_id,
                'location_id' => $l->location_id, 'headcount' => $l->headcount, 'fte' => (float) $l->fte, 'effective_date' => $l->effective_date->toDateString(),
                ...($costs ? ['planned_cost' => $l->planned_cost === null ? null : (float) $l->planned_cost, 'cost_basis' => $l->cost_basis] : []),
            ])->all(),
        ]]);
    }

    public function scenarios(Request $request): JsonResponse
    {
        return $this->page(WorkforceScenario::query()->orderBy('code'), $request, fn (WorkforceScenario $s) => [
            'code' => $s->code, 'name' => $s->name, 'status' => $s->status,
            'assumptions' => collect($s->assumptions ?? [])->map(fn ($value, $key) => ['key' => $key, 'value' => $value, 'label' => config("peopleos.workforce.scenario_assumptions.{$key}")])->values()->all(),
        ]);
    }

    public function headcount(Request $request, WorkforceSnapshot $snapshot): JsonResponse
    {
        return response()->json(['data' => $snapshot->headcount($this->on($request))]);
    }

    public function snapshot(Request $request, WorkforceSnapshot $snapshot): JsonResponse
    {
        $by = $request->query('by', 'department_id');
        if (! in_array($by, ['department_id', 'location_id', 'job_family_id', 'employment_type_id', 'business_unit_id', 'grade_id', 'designation_id', 'company_id'], true)) {
            return response()->json(['message' => 'Unknown grouping.'], 422);
        }

        return response()->json(['data' => ['as_of' => $this->on($request), 'by' => $by, 'rows' => $snapshot->by($by, $this->on($request))]]);
    }

    private function costs(Request $request): bool
    {
        return (bool) $request->attributes->get('api_key')?->hasScope('workforce.costs');
    }

    private function on(Request $request): string
    {
        return Carbon::parse($request->query('on') ?: now())->toDateString();
    }
}
