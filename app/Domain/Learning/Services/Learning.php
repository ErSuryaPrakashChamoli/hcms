<?php

namespace App\Domain\Learning\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseModule;
use App\Domain\Learning\Models\LearningAssignment;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Enrolment, requests and approvals, assignment, progress and completion (§37, Phase 8).
 *
 * - Every enrolment pins the course version (and path version) it was made on.
 * - Status changes follow LearningEnrolment::TRANSITIONS; completion is a finalized record
 *   (Completions), never just progress reaching 100%.
 * - Manager scope comes from the PeopleOS relationship resolver (configured relationship types).
 * - Requests are approved by someone other than the learner and the requester — through the
 *   course's approval workflow (pinned to its published version) when one is configured.
 */
final class Learning
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
        private readonly AuditRecorder $audit,
        private readonly Catalogue $catalogue,
        private readonly LearningPaths $paths,
        private readonly PerformanceRelationships $relationships,
        private readonly WorkflowEngine $workflows,
    ) {}

    // --- Enrolment -----------------------------------------------------------------------------

    public function enrol(Employee $employee, Course $course, ?CarbonInterface $dueOn = null, ?LearningAssignment $assignment = null, ?LearningPath $path = null, ?User $actor = null, bool $mandatory = false, string $status = 'enrolled', ?string $reason = null): LearningEnrolment
    {
        if (! $course->isPublished()) {
            throw new RuntimeException("Course {$course->code} is not published.");
        }

        $open = LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('course_id', $course->id)
            ->whereIn('status', [...LearningEnrolment::OPEN, ...LearningEnrolment::PENDING])->first();
        if ($open) {
            return $open; // idempotent: one open enrolment per course
        }

        $version = $this->catalogue->ensureVersion($course);
        $pathVersion = $path ? $this->paths->ensureVersion($path) : null;

        $enrolment = LearningEnrolment::create([
            'employee_id' => $employee->id,
            'course_id' => $course->id,
            'course_version_id' => $version->id,
            'learning_path_id' => $path?->id,
            'learning_path_version_id' => $pathVersion?->id,
            'learning_assignment_id' => $assignment?->id,
            'assignment_version' => $assignment?->version,
            'status' => $status,
            'is_mandatory' => $mandatory || $course->is_mandatory || (bool) $assignment?->is_mandatory,
            'priority' => $assignment?->priority,
            'reason' => $reason ?? $assignment?->reason,
            'due_on' => $dueOn,
            'enrolled_by' => $actor?->id ?? auth()->id(),
        ]);
        $this->catalogue->markActive($course);

        LearningEvent::dispatch('learning.assigned', $employee, $enrolment, ['course' => $version->title, 'due_on' => $dueOn?->toDateString(), 'mandatory' => $enrolment->is_mandatory]);

        return $enrolment;
    }

    /** Enrol into every course of a path (required and optional), in order, pinned to the path version. */
    public function enrolPath(Employee $employee, LearningPath $path, ?CarbonInterface $dueOn = null, ?LearningAssignment $assignment = null, ?User $actor = null, string $status = 'enrolled'): Collection
    {
        $this->paths->ensureVersion($path);

        return $path->items()->with('course')->get()
            ->filter(fn ($i) => $i->course?->isPublished())
            ->map(fn ($i) => $this->enrol($employee, $i->course, $dueOn, $assignment, $path, $actor, $i->is_required && (bool) $assignment?->is_mandatory, $status));
    }

    // --- Requests and approvals ---------------------------------------------------------------

    /**
     * An employee (or someone for them) asks for learning. Courses that allow self-enrolment and
     * need no approval enrol directly; otherwise the request waits for approval.
     */
    public function request(Employee $employee, Course $course, string $reason, ?User $actor = null): LearningEnrolment
    {
        $actor ??= auth()->user();
        if (! $course->isPublished()) {
            throw new RuntimeException("Course {$course->code} is not open for requests.");
        }
        $self = $actor !== null && $this->relationships->forUser($actor)?->id === $employee->id;
        if ($self && ! $course->allow_self_enrol && ! $course->requires_approval) {
            throw new RuntimeException('This course is assigned by L&D or managers; it cannot be requested.');
        }
        if (! $self && $actor !== null && ! $this->mayAssign($actor, $employee)) {
            throw new RuntimeException('You can request learning only for yourself or for employees you manage.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Say why this learning is needed.');
        }

        return DB::transaction(function () use ($employee, $course, $reason, $actor) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->id)->lockForUpdate()->first();
            $existing = LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('course_id', $course->id)
                ->whereIn('status', [...LearningEnrolment::OPEN, ...LearningEnrolment::PENDING])->first();
            if ($existing) {
                return $existing;
            }
            $needsApproval = $course->requires_approval;
            $enrolment = $this->enrol($employee, $course, null, null, null, $actor, false, $needsApproval ? 'requested' : 'enrolled', $reason);
            $enrolment->update(['requested_by' => $actor?->id, 'requested_at' => now()]);
            $this->audit->record(AuditAction::Submitted, 'learning', $enrolment, [], $reason, actor: $actor, metadata: ['event' => 'enrolment_requested']);
            LearningEvent::dispatch('learning.enrolment.requested', $employee, $enrolment, ['course' => $course->title]);

            if ($needsApproval) {
                $enrolment->update(['status' => 'pending_approval']);
                $workflow = $course->approval_workflow_key ? Workflow::query()->where('key', $course->approval_workflow_key)->where('status', 'active')->first() : null;
                if ($workflow && $workflow->published()->exists()) {
                    // The engine pins the instance to the workflow's published version.
                    $instance = $this->workflows->start($workflow, $enrolment, ['learning' => ['course' => $course->code, 'employee_id' => $employee->id, 'reason' => $reason]], $actor);
                    $enrolment->update(['workflow_instance_id' => $instance->id]);
                }
            }

            return $enrolment->refresh();
        });
    }

    /**
     * Approve or reject a pending request. Never by the learner or the requester; a person needs
     * learning.manage, or learning.approve and a configured relationship with the learner. A null
     * actor is the approval workflow deciding.
     */
    public function decide(LearningEnrolment $enrolment, bool $approve, ?string $note = null, ?User $actor = null, bool $byWorkflow = false): LearningEnrolment
    {
        if (! $byWorkflow) {
            $actor ??= auth()->user();
            if ($actor === null) {
                throw new RuntimeException('A decision needs a person or the approval workflow.');
            }
            if ($this->relationships->forUser($actor)?->id === $enrolment->employee_id || (int) $enrolment->requested_by === (int) $actor->id) {
                throw new RuntimeException('Learning requests cannot be approved by the learner or the requester.');
            }
            $manages = $actor->hasPermission('learning.approve') && $this->relationships->manages($this->relationships->forUser($actor), $enrolment->employee_id);
            if (! $actor->hasPermission('learning.manage') && ! $manages) {
                throw new RuntimeException('Only L&D or a manager of this employee (with learning.approve) decides this request.');
            }
        }
        if (! $approve && trim((string) $note) === '') {
            throw new RuntimeException('A reason is required to reject a request.');
        }

        return DB::transaction(function () use ($enrolment, $approve, $note, $actor, $byWorkflow) {
            $current = LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, ['requested', 'pending_approval'], true)) {
                throw new RuntimeException('This request was already decided.');
            }
            $enrolment->setRawAttributes($current->getAttributes(), true);
            $enrolment->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $actor?->id, 'approved_at' => now(), 'decision_note' => $note]);
            if ($approve) {
                $enrolment->update(['status' => 'enrolled']);
            }
            $this->audit->record($approve ? AuditAction::Approved : AuditAction::Rejected, 'learning', $enrolment, [], $note, actor: $actor, metadata: ['event' => $approve ? 'enrolment_approved' : 'enrolment_rejected', 'by_workflow' => $byWorkflow]);
            LearningEvent::dispatch($approve ? 'learning.enrolment.approved' : 'learning.enrolment.rejected', $enrolment->employee()->withoutGlobalScope(AccessScope::class)->first(), $enrolment, ['course' => $enrolment->course()->value('title')]);

            return $enrolment;
        });
    }

    // --- Assignment ------------------------------------------------------------------------------

    /**
     * Create and apply an assignment as one audited bulk operation (operation id on the assignment
     * and on every audit event inside). Managers may assign to employees and teams they manage;
     * organisation units and rule-defined populations need learning.manage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function assign(array $attributes, ?User $actor = null): LearningAssignment
    {
        $actor ??= auth()->user();
        $type = $attributes['target_type'] ?? (isset($attributes['employee_id']) ? 'employee' : 'population');
        if ($actor !== null && ! $actor->hasPermission('learning.manage')) {
            if (! $actor->hasPermission('learning.assign')) {
                throw new RuntimeException('Assigning learning needs learning.assign.');
            }
            $me = $this->relationships->forUser($actor);
            $allowed = match ($type) {
                'employee' => $this->relationships->manages($me, $attributes['employee_id'] ?? null),
                'team' => $me !== null && (int) ($attributes['target_id'] ?? 0) === (int) $me->id,
                default => false,
            };
            if (! $allowed) {
                throw new RuntimeException('Managers assign learning only to employees and teams they manage; organisation units and populations need learning.manage.');
            }
        }

        $assignment = null;
        $this->audit->operation('learning', 'Learning assignment', function (string $operationId) use (&$assignment, $attributes, $type, $actor) {
            $assignment = LearningAssignment::query()->create([...$attributes, 'target_type' => $type, 'operation_id' => $operationId, 'assigned_at' => now(), 'created_by' => $actor?->id]);
            $created = $this->applyAssignment($assignment, $actor);

            return ['succeeded' => $created->count(), 'ids' => $created->take(100)->all()];
        }, $attributes['reason'] ?? null, LearningAssignment::class);

        return $assignment->refresh();
    }

    /** Apply one assignment to its target population. Processes employees in chunks. Returns new enrolment employee ids. */
    public function applyAssignment(LearningAssignment $assignment, ?User $actor = null): Collection
    {
        if ($assignment->status !== 'active' || ($assignment->effective_to && $assignment->effective_to->lt(now()->startOfDay())) || ($assignment->effective_from && $assignment->effective_from->isFuture())) {
            return collect();
        }

        $dueOn = now()->addDays($assignment->due_days)->startOfDay();
        $created = collect();
        $status = 'assigned';

        $this->targets($assignment)->chunkById((int) config('peopleos.learning.assignment_chunk', 500), function ($employees) use ($assignment, $actor, $dueOn, $status, $created) {
            foreach ($employees as $employee) {
                if (! empty($assignment->conditions) && ! $this->rules->matches($assignment->conditions, $this->context->build($employee))) {
                    continue;
                }
                if ($this->alreadyCovered($assignment, $employee)) {
                    continue;
                }
                $made = $assignment->course_id
                    ? collect([$this->enrol($employee, $assignment->course, $dueOn, $assignment, null, $actor, false, $status)])
                    : $this->enrolPath($employee, $assignment->path, $dueOn, $assignment, $actor, $status);
                if ($made->contains(fn (LearningEnrolment $e) => $e->wasRecentlyCreated)) {
                    $created->push($employee->id);
                }
            }
        }, 'employees.id', 'id');

        return $created;
    }

    /** Cancel an assignment; enrolments it created that have not started are cancelled with it. */
    public function cancelAssignment(LearningAssignment $assignment, string $reason, ?User $actor = null): int
    {
        $actor ??= auth()->user();
        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required to cancel an assignment.');
        }

        return DB::transaction(function () use ($assignment, $reason, $actor) {
            $current = LearningAssignment::query()->withoutGlobalScope(AccessScope::class)->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'cancelled') {
                throw new RuntimeException('This assignment is already cancelled.');
            }
            $assignment->setRawAttributes($current->getAttributes(), true);
            $assignment->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor?->id, 'cancellation_reason' => $reason]);

            return LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->where('learning_assignment_id', $assignment->id)->whereIn('status', ['assigned', 'enrolled', 'waitlisted'])->whereNull('started_at')->get()
                ->each(fn (LearningEnrolment $e) => $this->cancel($e, 'Assignment cancelled: '.$reason, $actor))->count();
        });
    }

    /** The employees an assignment targets, as a chunkable query (never the whole tenant in memory). */
    public function targets(LearningAssignment $assignment): Builder
    {
        $query = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->employed();

        return match ($assignment->target_type) {
            'employee' => $query->whereKey($assignment->employee_id),
            'team' => $query->whereIn('employees.id', $this->relationships->reportIds(Employee::query()->withoutGlobalScope(AccessScope::class)->find($assignment->target_id))),
            'organisation_unit' => $query->whereIn('employees.id', $this->unitMembers((int) $assignment->target_id)),
            default => $query,
        };
    }

    /** Employees whose current position sits in an organisation unit (company, business unit, division, department, team or location). */
    private function unitMembers(int $nodeId)
    {
        $node = OrganisationNode::query()->findOrFail($nodeId);
        $column = app(OrganisationTree::class)->typeKeyForClass($node->nodeable_type).'_id';
        if (! in_array($column, (new EmployeePosition)->getFillable(), true)) {
            throw new RuntimeException('That organisation unit type cannot be targeted.');
        }

        return EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->effectiveOn()->where($column, $node->nodeable_id)->select('employee_id');
    }

    public function mayAssign(User $actor, Employee $employee): bool
    {
        return $actor->hasPermission('learning.manage')
            || ($actor->hasPermission('learning.assign') && $this->relationships->manages($this->relationships->forUser($actor), $employee->id));
    }

    /** Already open, or completed and still within the recurrence window (or non-recurring). */
    private function alreadyCovered(LearningAssignment $assignment, Employee $employee): bool
    {
        $query = LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('learning_assignment_id', $assignment->id);

        if ((clone $query)->whereIn('status', [...LearningEnrolment::OPEN, ...LearningEnrolment::PENDING])->exists()) {
            return true;
        }

        $last = (clone $query)->where('status', 'completed')->latest('completed_at')->first();
        if ($last === null) {
            return false;
        }

        return $assignment->recur_months === null || $last->completed_at->addMonths($assignment->recur_months)->isFuture();
    }

    // --- Progress ------------------------------------------------------------------------------

    /** Starting requires the course's (and the path's) prerequisites to be completed. */
    public function start(LearningEnrolment $enrolment): LearningEnrolment
    {
        if (! in_array($enrolment->status, ['assigned', 'enrolled', 'approved', 'overdue'], true)) {
            return $enrolment;
        }
        $missing = $this->missingPrerequisites($enrolment);
        if ($missing !== []) {
            throw new RuntimeException('Complete the prerequisites first: '.implode(', ', $missing).'.');
        }
        if ($enrolment->status !== 'overdue') {
            $enrolment->update(['started_at' => $enrolment->started_at ?? now(), 'status' => 'in_progress']);
            LearningEvent::dispatch('learning.started', $enrolment->employee()->withoutGlobalScope(AccessScope::class)->first(), $enrolment, ['course' => $enrolment->course()->value('title')]);
        } elseif ($enrolment->started_at === null) {
            $enrolment->update(['started_at' => now()]);
        }

        return $enrolment;
    }

    /** @return list<string> course codes still to be completed */
    public function missingPrerequisites(LearningEnrolment $enrolment): array
    {
        $required = array_map('intval', Course::query()->find($enrolment->course_id)?->prerequisite_course_ids ?? []);
        if ($enrolment->learning_path_version_id) {
            $required = [...$required, ...$enrolment->pathVersion()->firstOrFail()->prerequisitesFor((int) $enrolment->course_id)];
        }
        $required = array_values(array_unique($required));
        if ($required === []) {
            return [];
        }
        $done = LearningCompletion::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $enrolment->employee_id)->whereIn('course_id', $required)->where('status', 'final')->pluck('course_id')->map(fn ($id) => (int) $id)->all();

        return Course::query()->whereIn('id', array_diff($required, $done))->orderBy('code')->pluck('code')->all();
    }

    /**
     * Learner-reported progress for self-paced, on-the-job, blended and external learning (instructor-led
     * progress comes from attendance). Progress is not completion: reaching 100% does not complete.
     */
    public function updateProgress(LearningEnrolment $enrolment, float $percent, ?User $actor = null): LearningEnrolment
    {
        $actor ??= auth()->user();
        if ($percent < 0 || $percent > 100) {
            throw new RuntimeException('Progress is between 0 and 100.');
        }
        $own = $actor !== null && $this->relationships->forUser($actor)?->id === $enrolment->employee_id;
        // A null actor is a system integration (API key with learning.write).
        if ($actor !== null && ! $own && ! ($actor->hasPermission('learning.assign') || $actor->hasPermission('learning.manage'))) {
            throw new RuntimeException('Only the learner or L&D update this progress.');
        }
        $mode = $enrolment->courseVersion()->value('delivery_mode');
        if ($own && ! in_array($mode, config('peopleos.learning.self_reported_progress_modes', []), true)) {
            throw new RuntimeException('Progress on this learning comes from modules, attendance or the assessment.');
        }

        return DB::transaction(function () use ($enrolment, $percent, $actor) {
            $current = LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();
            if (! $current->isOpen()) {
                throw new RuntimeException('This enrolment is closed.');
            }
            $enrolment->setRawAttributes($current->getAttributes(), true);
            $this->start($enrolment);
            $before = (float) $enrolment->progress;
            $enrolment->update(['progress' => round($percent, 2)]);
            $this->audit->record(AuditAction::Update, 'learning', $enrolment, [['field' => 'progress', 'before' => $before, 'after' => round($percent, 2)]], null, actor: $actor, metadata: ['event' => 'progress_changed']);

            return $enrolment;
        });
    }

    public function completeModule(LearningEnrolment $enrolment, CourseModule $module): LearningEnrolment
    {
        if (! $enrolment->isOpen()) {
            throw new RuntimeException('This enrolment is closed.');
        }
        $curriculum = collect($enrolment->courseVersion?->curriculum ?? []);
        $inVersion = $curriculum->isEmpty() ? $module->course_id === $enrolment->course_id : $curriculum->contains(fn ($m) => (int) $m['id'] === $module->id);
        if ($module->course_id !== $enrolment->course_id || ! $inVersion) {
            throw new RuntimeException('That module belongs to another course or version.');
        }

        $this->start($enrolment);
        $done = collect($enrolment->completed_module_ids ?? [])->push($module->id)->unique()->values()->all();
        $enrolment->update(['completed_module_ids' => $done]);
        $this->recomputeProgress($enrolment);

        return $enrolment->refresh();
    }

    /** Progress = completed content modules / content modules of the pinned version. The assessment (if any) gates completion. */
    public function recomputeProgress(LearningEnrolment $enrolment): void
    {
        $course = $enrolment->course()->firstOrFail();
        $version = $enrolment->course_version_id ? $enrolment->courseVersion()->firstOrFail() : $this->catalogue->ensureVersion($course);
        $content = collect($version->curriculum ?? [])->where('type', '!=', 'assessment');
        $doneIds = $enrolment->completed_module_ids ?? [];
        $assessment = $version->assessment;
        $progress = $content->isEmpty() ? ($assessment ? 0 : 100) : round($content->filter(fn ($m) => in_array((int) $m['id'], $doneIds, true))->count() / $content->count() * 100, 2);

        $enrolment->update(['progress' => $progress]);

        $passing = $assessment['passing_score'] ?? $version->passing_score ?? 0;
        $needsAssessment = $assessment !== null && ! ($enrolment->score !== null && (float) $enrolment->score >= $passing);

        if ($progress >= 100 && ! $needsAssessment && ! $course->isInstructorLed() && $enrolment->isOpen()) {
            $this->complete($enrolment);
        }
    }

    /** Finalize completion (Completions). Kept here for existing callers. */
    public function complete(LearningEnrolment $enrolment, ?float $score = null, ?User $actor = null, array $details = []): LearningEnrolment
    {
        app(Completions::class)->finalize($enrolment, $score, $actor, $details);

        return $enrolment->refresh();
    }

    public function fail(LearningEnrolment $enrolment): LearningEnrolment
    {
        $enrolment->update(['status' => 'failed']);
        LearningEvent::dispatch('learning.failed', $enrolment->employee()->withoutGlobalScope(AccessScope::class)->with('person')->firstOrFail(), $enrolment, ['course' => $enrolment->course()->value('title')]);

        return $enrolment;
    }

    public function withdraw(LearningEnrolment $enrolment, string $reason, ?User $actor = null): LearningEnrolment
    {
        if (! $enrolment->isOpen() && ! $enrolment->isPending()) {
            throw new RuntimeException('This enrolment is closed.');
        }
        $enrolment->withAuditReason($reason)->update(['status' => 'withdrawn']);

        return $enrolment;
    }

    /** Cancel learning that is no longer needed (assignment cancelled, request withdrawn, session cancelled). */
    public function cancel(LearningEnrolment $enrolment, string $reason, ?User $actor = null): LearningEnrolment
    {
        if (! $enrolment->isOpen() && ! $enrolment->isPending()) {
            throw new RuntimeException('This enrolment is closed.');
        }
        $enrolment->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        LearningEvent::dispatch('learning.enrolment.cancelled', $enrolment->employee()->withoutGlobalScope(AccessScope::class)->first(), $enrolment, ['course' => $enrolment->course()->value('title')]);

        return $enrolment;
    }

    // --- Daily automation -------------------------------------------------------------------

    /** @return array{assigned: int, overdue: int, due_soon: int, expiring: int, expired: int, recertification: int, released: int} */
    public function tick(?CarbonInterface $today = null): array
    {
        $today = Carbon::parse($today ?? now())->startOfDay();
        $result = ['assigned' => 0, 'overdue' => 0, 'due_soon' => 0, 'expiring' => 0, 'expired' => 0, 'recertification' => 0, 'released' => $this->catalogue->releaseScheduled($today)];

        LearningAssignment::query()->where('status', 'active')->where(fn ($q) => $q->whereNotNull('conditions')->orWhereNotNull('recur_months')->orWhereIn('target_type', ['team', 'organisation_unit', 'population']))->where('auto_enrol_new_joiners', true)->get()
            ->each(function (LearningAssignment $a) use (&$result) {
                $result['assigned'] += $this->applyAssignment($a)->count();
            });

        LearningEnrolment::query()->with(['employee.person', 'course'])->whereIn('status', ['assigned', 'enrolled', 'in_progress'])->whereNotNull('due_on')->whereDate('due_on', '<', $today)
            ->chunkById(500, function ($enrolments) use (&$result) {
                foreach ($enrolments as $e) {
                    $e->update(['status' => 'overdue']);
                    LearningEvent::dispatch('learning.overdue', $e->employee, $e, ['course' => $e->course->title, 'due_on' => $e->due_on->toDateString()]);
                    $result['overdue']++;
                }
            });

        $soon = (int) config('peopleos.learning.due_soon_days', 7);
        LearningEnrolment::query()->with(['employee.person', 'course'])->whereIn('status', ['assigned', 'enrolled', 'in_progress'])->whereDate('due_on', '=', $today->copy()->addDays($soon))->get()
            ->each(function (LearningEnrolment $e) use (&$result) {
                LearningEvent::dispatch('learning.due_soon', $e->employee, $e, ['course' => $e->course->title, 'due_on' => $e->due_on->toDateString()]);
                $result['due_soon']++;
            });

        return [...$result, ...app(Certificates::class)->tick($today)];
    }

    public function forEmployee(Employee $employee)
    {
        return LearningEnrolment::query()->with(['course', 'path'])->where('employee_id', $employee->id)->orderByRaw("case when status in ('overdue') then 0 when status in ('assigned','enrolled','in_progress') then 1 else 2 end")->orderBy('due_on')->get();
    }
}
