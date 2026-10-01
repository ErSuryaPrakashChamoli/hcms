<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Employment\Models\Employee;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseVersion;
use App\Domain\Learning\Models\LearningAssignment;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Models\LearningProgram;
use App\Domain\Learning\Services\Learning;
use App\Domain\Learning\Services\LearningAnalytics;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Skills\Models\SkillAssessment;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Phase 8 learning API (`/api/v1/learning/*`). Scope learning.read; writes need learning.write.
 * Employees are addressed by employee_code (never internal ids). Never returned: certificate
 * verification codes or document paths, evidence files, assessment comments / evidence / private
 * notes, development-plan summaries or private notes, manager comments. Costs only with the
 * learning.costs scope. Ids from another tenant resolve to 404 (the key binds the tenant).
 */
class LearningController extends Controller
{
    public function catalogue(Request $request): JsonResponse
    {
        $query = Course::query()->with(['currentVersion', 'provider'])->whereIn('status', Course::ENROLLABLE)
            ->when($request->query('category'), fn (Builder $q, $c) => $q->where('category', $c))
            ->when($request->query('delivery_mode'), fn (Builder $q, $m) => $q->where('delivery_mode', $m))
            ->orderBy('title');

        return $this->page($query, $request, fn (Course $c) => $this->course($c, $request));
    }

    public function courses(Request $request): JsonResponse
    {
        $query = Course::query()->with(['currentVersion', 'provider'])->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))->orderBy('code');

        return $this->page($query, $request, fn (Course $c) => $this->course($c, $request));
    }

    public function courseVersions(Request $request, string $code): JsonResponse
    {
        $course = Course::query()->where('code', strtoupper($code))->firstOrFail();

        return $this->page(CourseVersion::query()->where('course_id', $course->id)->orderBy('version'), $request, fn (CourseVersion $v) => [
            'version' => $v->version, 'title' => $v->title, 'delivery_mode' => $v->delivery_mode, 'duration_minutes' => $v->duration_minutes,
            'modules' => count($v->curriculum ?? []), 'has_assessment' => $v->assessment !== null, 'validity_months' => $v->validity_months,
            'checksum' => $v->checksum, 'effective_from' => $v->effective_from?->toDateString(), 'published_at' => $v->published_at?->toIso8601String(),
        ]);
    }

    public function paths(Request $request): JsonResponse
    {
        return $this->page(LearningPath::query()->with('currentVersion')->orderBy('code'), $request, fn (LearningPath $p) => [
            'code' => $p->code, 'name' => $p->name, 'version' => $p->currentVersion?->version,
            'items' => collect($p->currentVersion?->items ?? [])->map(fn ($i) => ['course_code' => $i['course_code'], 'required' => $i['required'], 'prerequisites' => $i['prerequisite_course_ids']])->all(),
            'milestones' => collect($p->currentVersion?->milestones ?? [])->pluck('title')->all(),
        ]);
    }

    public function programs(Request $request): JsonResponse
    {
        return $this->page(LearningProgram::query()->with('currentVersion')->orderBy('code'), $request, fn (LearningProgram $p) => [
            'code' => $p->code, 'name' => $p->name, 'status' => $p->status, 'version' => $p->currentVersion?->version,
            'starts_on' => $p->currentVersion?->starts_on?->toDateString(), 'ends_on' => $p->currentVersion?->ends_on?->toDateString(),
            'items' => count($p->currentVersion?->items ?? []), 'min_optional' => $p->currentVersion?->completion_rule['min_optional'] ?? null,
            'issues_certificate' => (bool) $p->currentVersion?->issues_certificate,
        ]);
    }

    public function enrolments(Request $request): JsonResponse
    {
        $query = LearningEnrolment::query()->with(['employee:id,employee_code', 'course:id,code', 'courseVersion:id,version'])
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))
            ->orderByDesc('id');

        return $this->page($query, $request, fn (LearningEnrolment $e) => $this->enrolment($e));
    }

    public function assignments(Request $request): JsonResponse
    {
        return $this->page(LearningAssignment::query()->with(['course:id,code', 'path:id,code'])->orderByDesc('id'), $request, fn (LearningAssignment $a) => [
            'id' => $a->id, 'name' => $a->name, 'course_code' => $a->course?->code, 'path_code' => $a->path?->code, 'target_type' => $a->target_type,
            'priority' => $a->priority, 'mandatory' => $a->is_mandatory, 'required' => $a->is_required, 'due_days' => $a->due_days, 'recur_months' => $a->recur_months,
            'version' => $a->version, 'status' => $a->status, 'operation_id' => $a->operation_id, 'assigned_at' => $a->assigned_at?->toIso8601String(),
        ]);
    }

    public function completions(Request $request): JsonResponse
    {
        $query = LearningCompletion::query()->with(['employee:id,employee_code', 'course:id,code', 'courseVersion:id,version'])
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->orderByDesc('id');

        return $this->page($query, $request, fn (LearningCompletion $c) => [
            'id' => $c->id, 'employee_code' => $c->employee?->employee_code, 'course_code' => $c->course?->code, 'course_version' => $c->courseVersion?->version,
            'program_version_id' => $c->learning_program_version_id, 'completed_at' => $c->completed_at?->toIso8601String(), 'score' => $c->score === null ? null : (float) $c->score,
            'grade' => $c->grade, 'attendance' => $c->attendance, 'hours' => $c->hours === null ? null : (float) $c->hours, 'status' => $c->status, 'sequence' => $c->sequence,
            'corrects_id' => $c->corrects_completion_id,
        ]);
    }

    public function certificates(Request $request): JsonResponse
    {
        $query = LearningCertificate::query()->with(['employee:id,employee_code', 'course:id,code', 'courseVersion:id,version'])
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->when($request->query('status'), fn (Builder $q, $s) => $q->where('status', $s))
            ->orderByDesc('id');

        return $this->page($query, $request, fn (LearningCertificate $c) => [
            'number' => $c->number, 'employee_code' => $c->employee?->employee_code, 'course_code' => $c->course?->code, 'course_version' => $c->courseVersion?->version,
            'issued_on' => $c->issued_on?->toDateString(), 'expires_on' => $c->expires_on?->toDateString(), 'status' => $c->status, 'issuer' => $c->issuer,
            'external' => $c->is_external, 'verification_status' => $c->verification_status, 'has_document' => $c->document_path !== null,
        ]);
    }

    public function skills(Request $request): JsonResponse
    {
        return $this->page(Skill::query()->orderBy('code'), $request, fn (Skill $s) => [
            'code' => $s->code, 'name' => $s->name, 'category' => $s->category, 'type' => $s->skill_type, 'status' => $s->status,
        ]);
    }

    /** Current levels per employee and skill — the source and whether it is verified; never the evidence. */
    public function employeeSkills(Request $request): JsonResponse
    {
        $query = EmployeeSkill::query()->with(['employee:id,employee_code', 'skill:id,code', 'scaleVersion:id,version,levels'])->where('status', 'current')
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->orderBy('employee_id')->orderBy('skill_id');

        return $this->page($query, $request, fn (EmployeeSkill $s) => [
            'employee_code' => $s->employee?->employee_code, 'skill_code' => $s->skill?->code, 'level' => $s->current_level === null ? null : (float) $s->current_level,
            'label' => $s->scaleVersion?->labelFor($s->current_level === null ? null : (float) $s->current_level), 'target' => $s->target_level === null ? null : (float) $s->target_level,
            'source' => $s->source, 'verified' => $s->is_verified, 'scale_version' => $s->scaleVersion?->version, 'valid_from' => $s->valid_from?->toDateString(),
        ]);
    }

    /** Finalized assessments only: level and type, never comments, evidence or private notes. */
    public function assessments(Request $request): JsonResponse
    {
        $query = SkillAssessment::query()->with(['employee:id,employee_code', 'skill:id,code'])->whereIn('status', ['finalized', 'superseded'])
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->orderByDesc('id');

        return $this->page($query, $request, fn (SkillAssessment $a) => [
            'id' => $a->id, 'employee_code' => $a->employee?->employee_code, 'skill_code' => $a->skill?->code, 'type' => $a->assessment_type,
            'level' => (float) $a->level, 'assessed_on' => $a->assessed_on?->toDateString(), 'valid_until' => $a->valid_until?->toDateString(),
            'status' => $a->status, 'corrects_id' => $a->corrects_assessment_id,
        ]);
    }

    /** Plan metadata only: no summary, private notes or item notes. */
    public function developmentPlans(Request $request): JsonResponse
    {
        $query = DevelopmentPlan::query()->with('employee:id,employee_code')->withCount(['items', 'items as open_items_count' => fn ($q) => $q->where('status', 'open')])
            ->when($request->query('employee_code'), fn (Builder $q, $c) => $q->whereHas('employee', fn ($e) => $e->where('employee_code', $c)))
            ->orderByDesc('id');

        return $this->page($query, $request, fn (DevelopmentPlan $p) => [
            'id' => $p->id, 'employee_code' => $p->employee?->employee_code, 'title' => $p->title, 'status' => $p->status,
            'starts_on' => $p->starts_on?->toDateString(), 'target_date' => $p->target_date?->toDateString(), 'completed_at' => $p->completed_at?->toIso8601String(),
            'items' => $p->items_count, 'open_items' => $p->open_items_count,
        ]);
    }

    public function analytics(Request $request, LearningAnalytics $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->summary($this->mayReadCosts($request), $request->query('from'), $request->query('to'))]);
    }

    /** Enrol an employee (idempotent: one open enrolment per course; a repeat returns the existing one with 200). */
    public function enrol(Request $request, Learning $learning): JsonResponse
    {
        $data = $request->validate(['employee_code' => ['required', 'string'], 'course_code' => ['required', 'string'], 'due_on' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:500']]);
        if (($key = $request->header('Idempotency-Key')) !== null && ($key === '' || strlen($key) > 64)) {
            return response()->json(['message' => 'Idempotency-Key must be 1–64 characters.'], 422);
        }
        $employee = Employee::query()->where('employee_code', $data['employee_code'])->firstOrFail();
        $course = Course::query()->where('code', strtoupper($data['course_code']))->firstOrFail();

        try {
            $enrolment = $learning->enrol($employee, $course, isset($data['due_on']) ? now()->parse($data['due_on']) : null, null, null, null, false, 'assigned', $data['reason'] ?? 'Assigned through the API');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->enrolment($enrolment->load(['employee:id,employee_code', 'course:id,code', 'courseVersion:id,version']))], $enrolment->wasRecentlyCreated ? 201 : 200);
    }

    /** Set learner progress (absolute value, so retries are idempotent). Progress never completes learning. */
    public function progress(Request $request, int $enrolment, Learning $learning): JsonResponse
    {
        $data = $request->validate(['progress' => ['required', 'numeric', 'min:0', 'max:100']]);
        $enrolment = LearningEnrolment::query()->findOrFail($enrolment);

        try {
            $learning->updateProgress($enrolment, (float) $data['progress'], null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->enrolment($enrolment->refresh()->load(['employee:id,employee_code', 'course:id,code', 'courseVersion:id,version']))]);
    }

    private function course(Course $c, Request $request): array
    {
        return [
            'code' => $c->code, 'title' => $c->title, 'type' => $c->type, 'category' => $c->category, 'topic' => $c->topic, 'difficulty' => $c->difficulty,
            'delivery_mode' => $c->delivery_mode, 'language' => $c->language, 'duration_minutes' => $c->duration_minutes, 'provider' => $c->provider?->name,
            'mandatory' => $c->is_mandatory, 'self_enrol' => $c->allow_self_enrol, 'requires_approval' => $c->requires_approval, 'validity_months' => $c->validity_months,
            'status' => $c->status, 'version' => $c->currentVersion?->version, 'effective_from' => $c->effective_from?->toDateString(), 'effective_to' => $c->effective_to?->toDateString(),
            ...($this->mayReadCosts($request) ? ['cost' => $c->cost === null ? null : (float) $c->cost, 'currency' => $c->currency] : []),
        ];
    }

    private function enrolment(LearningEnrolment $e): array
    {
        return [
            'id' => $e->id, 'employee_code' => $e->employee?->employee_code, 'course_code' => $e->course?->code, 'course_version' => $e->courseVersion?->version,
            'status' => $e->status, 'mandatory' => $e->is_mandatory, 'priority' => $e->priority, 'progress' => (float) $e->progress, 'score' => $e->score === null ? null : (float) $e->score,
            'due_on' => $e->due_on?->toDateString(), 'started_at' => $e->started_at?->toIso8601String(), 'completed_at' => $e->completed_at?->toIso8601String(), 'expires_on' => $e->expires_on?->toDateString(),
        ];
    }

    private function mayReadCosts(Request $request): bool
    {
        return (bool) $request->attributes->get('api_key')?->hasScope('learning.costs');
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
