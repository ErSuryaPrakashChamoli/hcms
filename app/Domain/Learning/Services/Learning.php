<?php

namespace App\Domain\Learning\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseModule;
use App\Domain\Learning\Models\LearningAssignment;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Lifecycle\Services\Timeline;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Enrolment, progress, completion, certification and rule-based assignment (§37). */
final class Learning
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
    ) {}

    // --- Enrolment -----------------------------------------------------------------------------

    public function enrol(Employee $employee, Course $course, ?CarbonInterface $dueOn = null, ?LearningAssignment $assignment = null, ?LearningPath $path = null, ?User $actor = null, bool $mandatory = false): LearningEnrolment
    {
        if (! $course->isPublished()) {
            throw new RuntimeException("Course {$course->code} is not published.");
        }

        $open = LearningEnrolment::query()->where('employee_id', $employee->id)->where('course_id', $course->id)->whereIn('status', LearningEnrolment::OPEN)->first();
        if ($open) {
            return $open; // idempotent: one open enrolment per course
        }

        $enrolment = LearningEnrolment::create([
            'employee_id' => $employee->id,
            'course_id' => $course->id,
            'learning_path_id' => $path?->id,
            'learning_assignment_id' => $assignment?->id,
            'status' => 'enrolled',
            'is_mandatory' => $mandatory || $course->is_mandatory || (bool) $assignment?->is_mandatory,
            'due_on' => $dueOn,
            'enrolled_by' => $actor?->id ?? auth()->id(),
        ]);

        LearningEvent::dispatch('learning.assigned', $employee, $enrolment, ['course' => $course->title, 'due_on' => $dueOn?->toDateString(), 'mandatory' => $enrolment->is_mandatory]);

        return $enrolment;
    }

    /** Enrol into every course of a path (required and optional), in order. */
    public function enrolPath(Employee $employee, LearningPath $path, ?CarbonInterface $dueOn = null, ?LearningAssignment $assignment = null, ?User $actor = null): Collection
    {
        return $path->items()->with('course')->get()
            ->filter(fn ($i) => $i->course?->isPublished())
            ->map(fn ($i) => $this->enrol($employee, $i->course, $dueOn, $assignment, $path, $actor, $i->is_required));
    }

    /** Apply one assignment: enrol the named employee or everyone matching the conditions. Returns new enrolments. */
    public function applyAssignment(LearningAssignment $assignment, ?User $actor = null): Collection
    {
        if ($assignment->status !== 'active') {
            return collect();
        }

        $targets = $assignment->employee_id
            ? Employee::query()->with('person')->whereKey($assignment->employee_id)->get()
            : Employee::query()->with('person')->employed()->get()->filter(fn (Employee $e) => empty($assignment->conditions) || $this->rules->matches($assignment->conditions, $this->context->build($e)));

        $dueOn = now()->addDays($assignment->due_days)->startOfDay();
        $created = collect();

        foreach ($targets as $employee) {
            if ($this->alreadyCovered($assignment, $employee)) {
                continue;
            }
            $before = LearningEnrolment::query()->where('employee_id', $employee->id)->count();
            $assignment->course_id
                ? $this->enrol($employee, $assignment->course, $dueOn, $assignment, null, $actor)
                : $this->enrolPath($employee, $assignment->path, $dueOn, $assignment, $actor);
            if (LearningEnrolment::query()->where('employee_id', $employee->id)->count() > $before) {
                $created->push($employee->id);
            }
        }

        return $created;
    }

    /** Already open, or completed and still within the recurrence window (or non-recurring). */
    private function alreadyCovered(LearningAssignment $assignment, Employee $employee): bool
    {
        $query = LearningEnrolment::query()->where('employee_id', $employee->id)->where('learning_assignment_id', $assignment->id);

        if ((clone $query)->whereIn('status', LearningEnrolment::OPEN)->exists()) {
            return true;
        }

        $last = (clone $query)->where('status', 'completed')->latest('completed_at')->first();
        if ($last === null) {
            return false;
        }

        return $assignment->recur_months === null || $last->completed_at->addMonths($assignment->recur_months)->isFuture();
    }

    // --- Progress ------------------------------------------------------------------------------

    public function start(LearningEnrolment $enrolment): LearningEnrolment
    {
        if ($enrolment->status === 'enrolled' || $enrolment->status === 'overdue') {
            $enrolment->update(['started_at' => $enrolment->started_at ?? now(), 'status' => $enrolment->status === 'overdue' ? 'overdue' : 'in_progress']);
        }

        return $enrolment;
    }

    public function completeModule(LearningEnrolment $enrolment, CourseModule $module): LearningEnrolment
    {
        if (! $enrolment->isOpen()) {
            throw new RuntimeException('This enrolment is closed.');
        }
        if ($module->course_id !== $enrolment->course_id) {
            throw new RuntimeException('That module belongs to another course.');
        }

        $this->start($enrolment);
        $done = collect($enrolment->completed_module_ids ?? [])->push($module->id)->unique()->values()->all();
        $enrolment->update(['completed_module_ids' => $done]);
        $this->recomputeProgress($enrolment);

        return $enrolment->refresh();
    }

    /** Progress = completed content modules / content modules. The assessment (if any) gates completion. */
    public function recomputeProgress(LearningEnrolment $enrolment): void
    {
        $course = $enrolment->course()->with(['modules', 'assessment'])->firstOrFail();
        $content = $course->modules->where('type', '!=', 'assessment');
        $doneIds = $enrolment->completed_module_ids ?? [];
        $progress = $content->isEmpty() ? ($course->assessment ? 0 : 100) : round($content->filter(fn ($m) => in_array($m->id, $doneIds, true))->count() / $content->count() * 100, 2);

        $enrolment->update(['progress' => $progress]);

        $needsAssessment = $course->assessment !== null && ! ($enrolment->score !== null && (float) $enrolment->score >= ($course->assessment->passing_score ?? $course->passing_score ?? 0));
        $instructorLed = $course->isInstructorLed();

        if ($progress >= 100 && ! $needsAssessment && ! $instructorLed && $enrolment->isOpen()) {
            $this->complete($enrolment);
        }
    }

    public function complete(LearningEnrolment $enrolment, ?float $score = null, ?User $actor = null): LearningEnrolment
    {
        if (! $enrolment->isOpen()) {
            throw new RuntimeException('This enrolment is closed.');
        }

        return DB::transaction(function () use ($enrolment, $score, $actor) {
            $course = $enrolment->course()->firstOrFail();
            $employee = $enrolment->employee()->with('person')->firstOrFail();
            $expires = $course->validity_months ? now()->addMonths($course->validity_months)->startOfDay() : null;

            $enrolment->update(['status' => 'completed', 'progress' => 100, 'score' => $score ?? $enrolment->score, 'completed_at' => now(), 'expires_on' => $expires]);

            if ($course->validity_months) {
                $certificate = LearningCertificate::create([
                    'employee_id' => $employee->id, 'course_id' => $course->id, 'learning_enrolment_id' => $enrolment->id,
                    'number' => $this->certificateNumber($course, $employee),
                    'issued_on' => now(), 'expires_on' => $expires, 'score' => $enrolment->score, 'status' => 'valid',
                ]);
                $this->audit->record(AuditAction::Create, 'learning', $certificate, [], null, actor: $actor);
            }

            $this->timeline->record($employee, 'learning', "Completed: {$course->title}", now(), null, $enrolment, ['score' => $enrolment->score]);
            LearningEvent::dispatch('learning.completed', $employee, $enrolment, ['course' => $course->title, 'score' => $enrolment->score, 'expires_on' => $expires?->toDateString()]);

            return $enrolment->refresh();
        });
    }

    public function fail(LearningEnrolment $enrolment): LearningEnrolment
    {
        $enrolment->update(['status' => 'failed']);
        LearningEvent::dispatch('learning.failed', $enrolment->employee()->with('person')->firstOrFail(), $enrolment, ['course' => $enrolment->course()->value('title')]);

        return $enrolment;
    }

    public function withdraw(LearningEnrolment $enrolment, string $reason, ?User $actor = null): LearningEnrolment
    {
        if (! $enrolment->isOpen()) {
            throw new RuntimeException('This enrolment is closed.');
        }
        $enrolment->withAuditReason($reason)->update(['status' => 'withdrawn']);

        return $enrolment;
    }

    private function certificateNumber(Course $course, Employee $employee): string
    {
        $prefix = config('peopleos.learning.certificate_prefix', 'CERT');
        $base = sprintf('%s-%s-%s-%s', $prefix, $course->code, now()->format('Ym'), $employee->employee_code);
        $number = $base;
        $i = 1;
        while (LearningCertificate::query()->where('number', $number)->exists()) {
            $number = $base.'-'.(++$i);
        }

        return $number;
    }

    // --- Daily automation -------------------------------------------------------------------

    /** @return array{assigned: int, overdue: int, due_soon: int, expiring: int, expired: int} */
    public function tick(?CarbonInterface $today = null): array
    {
        $today = Carbon::parse($today ?? now())->startOfDay();
        $result = ['assigned' => 0, 'overdue' => 0, 'due_soon' => 0, 'expiring' => 0, 'expired' => 0];

        LearningAssignment::query()->where('status', 'active')->where(fn ($q) => $q->whereNotNull('conditions')->orWhereNotNull('recur_months'))->where('auto_enrol_new_joiners', true)->get()
            ->each(function (LearningAssignment $a) use (&$result) {
                $result['assigned'] += $this->applyAssignment($a)->count();
            });

        LearningEnrolment::query()->with(['employee.person', 'course'])->whereIn('status', ['enrolled', 'in_progress'])->whereNotNull('due_on')->whereDate('due_on', '<', $today)->get()
            ->each(function (LearningEnrolment $e) use (&$result) {
                $e->update(['status' => 'overdue']);
                LearningEvent::dispatch('learning.overdue', $e->employee, $e, ['course' => $e->course->title, 'due_on' => $e->due_on->toDateString()]);
                $result['overdue']++;
            });

        $soon = (int) config('peopleos.learning.due_soon_days', 7);
        LearningEnrolment::query()->with(['employee.person', 'course'])->whereIn('status', ['enrolled', 'in_progress'])->whereDate('due_on', '=', $today->copy()->addDays($soon))->get()
            ->each(function (LearningEnrolment $e) use (&$result) {
                LearningEvent::dispatch('learning.due_soon', $e->employee, $e, ['course' => $e->course->title, 'due_on' => $e->due_on->toDateString()]);
                $result['due_soon']++;
            });

        $notice = (int) config('peopleos.learning.certificate_expiry_notice_days', 30);
        LearningCertificate::query()->with(['employee.person', 'course'])->where('status', 'valid')->whereDate('expires_on', '=', $today->copy()->addDays($notice))->get()
            ->each(function (LearningCertificate $c) use (&$result) {
                $c->update(['status' => 'expiring']);
                LearningEvent::dispatch('learning.certificate_expiring', $c->employee, $c, ['course' => $c->course->title, 'expires_on' => $c->expires_on->toDateString()]);
                $result['expiring']++;
            });

        LearningCertificate::query()->whereIn('status', ['valid', 'expiring'])->whereDate('expires_on', '<', $today)->get()
            ->each(function (LearningCertificate $c) use (&$result) {
                $c->update(['status' => 'expired']);
                LearningEnrolment::query()->whereKey($c->learning_enrolment_id)->where('status', 'completed')->update(['status' => 'expired']);
                $result['expired']++;
            });

        return $result;
    }

    public function forEmployee(Employee $employee)
    {
        return LearningEnrolment::query()->with(['course', 'path'])->where('employee_id', $employee->id)->orderByRaw("case when status in ('overdue') then 0 when status in ('enrolled','in_progress') then 1 else 2 end")->orderBy('due_on')->get();
    }
}
