<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Career\Models\CareerGoal;
use App\Domain\Career\Models\CareerProfile;
use App\Domain\Career\Models\CareerTrack;
use App\Domain\Career\Models\MobilityInterest;
use App\Domain\Career\Models\RoleRequirementVersion;
use App\Domain\Career\Services\CareerMovements;
use App\Domain\Career\Services\RoleGaps;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Performance\Models\CareerPath;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9 career API (`/api/v1/career/*`, scope career.read). Employees are addressed by
 * employee_code. Only what the employee shares is returned: goals unless the employee stopped
 * sharing them, mobility only when shared. Never returned: aspiration text, development priorities,
 * goal descriptions or closure notes, mobility notes. Ids from another tenant resolve to 404.
 */
class CareerController extends Controller
{
    use PaginatesApi;

    public function tracks(Request $request): JsonResponse
    {
        return $this->page(CareerTrack::query()->orderBy('code'), $request, fn (CareerTrack $t) => ['code' => $t->code, 'name' => $t->name, 'type' => $t->track_type, 'status' => $t->status]);
    }

    public function paths(Request $request): JsonResponse
    {
        return $this->page(CareerPath::query()->with(['track:id,code', 'versions' => fn ($q) => $q->latest('version')])->orderBy('code'), $request, fn (CareerPath $p) => [
            'code' => $p->code, 'name' => $p->name, 'status' => $p->status, 'track' => $p->track?->code,
            'effective_from' => $p->effective_from?->toDateString(), 'effective_to' => $p->effective_to?->toDateString(),
            'version' => $p->versions->first()?->version,
            'steps' => collect($p->versions->first()?->steps ?? [])->map(fn ($s) => ['designation' => $s['designation'] ?? null, 'typical_years' => $s['typical_years'] ?? null])->all(),
        ]);
    }

    public function requirements(Request $request): JsonResponse
    {
        $query = RoleRequirementVersion::query()->with('designation:id,code')
            ->when($request->query('designation_code'), fn (Builder $q, $c) => $q->whereHas('designation', fn ($d) => $d->where('code', $c)))
            ->orderBy('designation_id')->orderBy('version');

        return $this->page($query, $request, fn (RoleRequirementVersion $r) => [
            'designation_code' => $r->designation?->code, 'organisation_node_id' => $r->organisation_node_id, 'version' => $r->version,
            'skills' => collect($r->skills ?? [])->map(fn ($s) => ['skill_id' => $s['skill_id'], 'level' => $s['level'], 'required' => $s['required'] ?? true, 'scale_version_id' => $s['skill_scale_version_id'] ?? null])->all(),
            'competency_ids' => collect($r->competencies ?? [])->pluck('competency_id')->all(), 'min_experience_years' => $r->min_experience_years === null ? null : (float) $r->min_experience_years,
            'certifications' => count($r->certifications ?? []), 'learning' => count($r->learning ?? []), 'checksum' => $r->checksum,
            'effective_from' => $r->effective_from?->toDateString(), 'effective_to' => $r->effective_to?->toDateString(),
        ]);
    }

    /** Career profiles: track and target roles; mobility preferences only when the employee shares them. */
    public function profiles(Request $request): JsonResponse
    {
        $query = CareerProfile::query()->with(['employee:id,employee_code', 'track:id,code'])->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))->orderBy('id');
        $designations = Designation::query()->pluck('code', 'id');

        return $this->page($query, $request, fn (CareerProfile $p) => [
            'employee_code' => $p->employee?->employee_code, 'track' => $p->track?->code,
            'target_designations' => collect($p->target_designation_ids ?? [])->map(fn ($id) => $designations[$id] ?? null)->filter()->values()->all(),
            'mobility' => $p->share_mobility_with_manager ? ($p->mobility ?? []) : null,
        ]);
    }

    public function goals(Request $request): JsonResponse
    {
        $query = CareerGoal::query()->with(['employee:id,employee_code', 'targetDesignation:id,code', 'skill:id,code'])
            ->whereNotIn('employee_id', CareerProfile::query()->where('share_goals_with_manager', false)->select('employee_id'))
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))->orderByDesc('id');

        return $this->page($query, $request, fn (CareerGoal $g) => [
            'id' => $g->id, 'employee_code' => $g->employee?->employee_code, 'title' => $g->title, 'type' => $g->goal_type, 'status' => $g->status,
            'target_designation' => $g->targetDesignation?->code, 'skill' => $g->skill?->code, 'target_level' => $g->target_level === null ? null : (float) $g->target_level,
            'target_date' => $g->target_date?->toDateString(), 'closed_at' => $g->closed_at?->toIso8601String(),
        ]);
    }

    public function mobility(Request $request): JsonResponse
    {
        $query = MobilityInterest::query()->with(['employee:id,employee_code', 'designation:id,code'])
            ->whereIn('employee_id', CareerProfile::query()->where('share_mobility_with_manager', true)->select('employee_id'))
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->where('status', $request->query('status', 'active'))->orderByDesc('id');

        return $this->page($query, $request, fn (MobilityInterest $m) => [
            'id' => $m->id, 'employee_code' => $m->employee?->employee_code, 'type' => $m->interest_type, 'designation' => $m->designation?->code,
            'job_family_id' => $m->job_family_id, 'department_id' => $m->department_id, 'location_id' => $m->location_id, 'career_track_id' => $m->career_track_id,
            'status' => $m->status, 'effective_from' => $m->effective_from?->toDateString(),
        ]);
    }

    /** Factual gaps for one employee against a role's current requirements. */
    public function gaps(Request $request, RoleGaps $gaps): JsonResponse
    {
        $data = $request->validate(['employee_code' => ['required', 'string'], 'designation_code' => ['required', 'string']]);
        $employee = Employee::query()->where('employee_code', $data['employee_code'])->firstOrFail();
        $designation = Designation::query()->where('code', $data['designation_code'])->firstOrFail();
        $result = $gaps->for($employee, $designation->id);

        return response()->json(['data' => [
            'employee_code' => $employee->employee_code, 'designation_code' => $designation->code,
            'requirements_version' => $result['requirements']['version'] ?? null,
            'skills' => collect($result['skills'])->map(fn ($s) => array_intersect_key($s, array_flip(['skill', 'required_level', 'current_level', 'basis', 'verified', 'comparable', 'gap'])))->all(),
            'learning' => collect($result['learning'])->map(fn ($l) => array_intersect_key($l, array_flip(['type', 'title', 'status'])))->all(),
            'certifications' => collect($result['certifications'])->map(fn ($c) => array_intersect_key($c, array_flip(['title', 'status', 'verified'])))->all(),
            'experience' => $result['experience'],
        ]]);
    }

    public function movements(Request $request, CareerMovements $movements): JsonResponse
    {
        $employee = Employee::query()->where('employee_code', (string) $request->query('employee_code'))->firstOrFail();

        return response()->json(['data' => collect($movements->for($employee))->map(fn ($m) => array_diff_key($m, ['reason' => true]))->all()]);
    }
}
