<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Career\Services\CareerArchitecture;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\CareerPathStep;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Workforce\Models\Position;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Phase 10 read-only links from a position to the modules that own the people side: role
 * requirements (Phase 9 career architecture), succession for the same role (Phase 9 critical
 * positions — unit-specific first, then role-wide) and career paths through its role. Nothing here
 * writes Career, Talent, Succession, Learning or Performance; callers decide who may see what.
 */
final class WorkforceIntegrations
{
    public function __construct(private readonly CareerArchitecture $architecture) {}

    /** @return array<string, mixed>|null the requirement version that applies to the position's role on the date */
    public function requirements(Position $position, CarbonInterface|string|null $on = null): ?array
    {
        $version = $position->versionOn($on) ?? $position->currentVersion;
        if (! $version?->designation_id) {
            return null;
        }
        $requirements = $this->architecture->requirementsFor((int) $version->designation_id, $version->organisation_node_id ? (int) $version->organisation_node_id : null, $on);

        return $requirements === null ? null : [
            'version' => $requirements->version, 'scope' => $requirements->organisation_node_id ? 'organisation unit' : 'role-wide',
            'skills' => count($requirements->skills ?? []), 'competencies' => count($requirements->competencies ?? []),
            'certifications' => count($requirements->certifications ?? []), 'learning' => count($requirements->learning ?? []),
            'min_experience_years' => $requirements->min_experience_years === null ? null : (float) $requirements->min_experience_years,
            'effective_from' => $requirements->effective_from->toDateString(),
        ];
    }

    /** @return array<string, mixed>|null succession coverage of the critical position matching this position's role */
    public function succession(Position $position): ?array
    {
        $version = $position->currentVersion;
        if (! $version?->designation_id) {
            return null;
        }
        $critical = CriticalPosition::query()->where('designation_id', $version->designation_id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('organisation_node_id')->when($version->organisation_node_id, fn ($w, $node) => $w->orWhere('organisation_node_id', $node)))
            ->orderByRaw('case when organisation_node_id is null then 1 else 0 end')->first();
        if ($critical === null) {
            return null;
        }
        $plan = SuccessionPlan::query()->where('critical_position_id', $critical->id)->where('status', '!=', 'closed')->first();
        $successorIds = $plan ? Successor::query()->withoutGlobalScope(AccessScope::class)->where('succession_plan_id', $plan->id)->where('status', 'active')->pluck('employee_id') : collect();
        $today = now()->toDateString();

        return [
            'critical_position' => $critical->title, 'criticality' => $critical->currentAssessment?->criticality, 'plan_status' => $plan?->status,
            'successors' => $successorIds->count(),
            'ready_now' => $successorIds->isEmpty() ? 0 : ReadinessAssessment::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $successorIds)
                ->where('critical_position_id', $critical->id)->where('status', 'current')->where('readiness_level', 'ready_now')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->distinct()->count('employee_id'),
        ];
    }

    /** @return list<array{path: string, track: ?string, next: list<string>}> career paths through the position's role and the next steps */
    public function careerPaths(Position $position): array
    {
        $designationId = $position->currentVersion?->designation_id;
        if (! $designationId) {
            return [];
        }

        return CareerPathStep::query()->with('path.track')->where('designation_id', $designationId)->get()
            ->map(function (CareerPathStep $step) {
                $next = CareerPathStep::query()->with('designation')->where('career_path_id', $step->career_path_id)->where('sort_order', '>', $step->sort_order)->orderBy('sort_order')->limit(2)->get();

                return ['path' => $step->path?->name, 'track' => $step->path?->track?->name, 'next' => $next->pluck('designation.name')->filter()->values()->all()];
            })->filter(fn ($row) => $row['path'] !== null)->values()->all();
    }

    /** Positions whose role has career paths: none are moved; this only answers "what could come next". */
    public function nextPositions(Position $position, CarbonInterface|string|null $on = null): array
    {
        $day = Carbon::parse($on ?? now())->toDateString();
        $designations = collect($this->careerPaths($position))->flatMap(fn ($p) => $p['next']);
        if ($designations->isEmpty()) {
            return [];
        }

        return Position::query()->whereIn('status', ['open', 'planned', 'approved'])->whereHas('designation', fn ($q) => $q->whereIn('name', $designations))
            ->limit(20)->get(['id', 'code', 'title', 'status'])->map(fn ($p) => ['code' => $p->code, 'title' => $p->title, 'status' => $p->status, 'as_of' => $day])->all();
    }
}
