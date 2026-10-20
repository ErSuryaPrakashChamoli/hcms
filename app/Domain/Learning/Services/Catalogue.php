<?php

namespace App\Domain\Learning\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8 catalogue lifecycle: draft → pending approval → approved (by a second person with
 * learning.publish) → published / scheduled → active → retired → archived. Publishing snapshots
 * an immutable course version (curriculum, assessment, provider, instructor, validity, cost,
 * skill outcomes); the course's current version is what new enrolments pin.
 */
final class Catalogue
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function submitForApproval(Course $course, ?User $actor = null): Course
    {
        $actor ??= auth()->user();
        $this->assertPrerequisitesAcyclic($course);

        return $this->transition($course, 'pending_approval', null, $actor, ['submitted_by' => $actor?->id, 'submitted_at' => now(), 'approved_by' => null, 'approved_at' => null]);
    }

    /** A second person approves: never the submitter, and only with learning.publish. */
    public function approve(Course $course, User $approver, ?string $note = null): Course
    {
        if (! $approver->hasPermission('learning.publish')) {
            throw new RuntimeException('Approving catalogue items needs learning.publish.');
        }
        if ($course->submitted_by !== null && (int) $course->submitted_by === (int) $approver->id) {
            throw new RuntimeException('The person who submitted a course cannot approve it.');
        }

        return $this->transition($course, 'approved', $note, $approver, ['approved_by' => $approver->id, 'approved_at' => now()]);
    }

    public function reject(Course $course, User $approver, string $reason): Course
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required to send a course back.');
        }

        return $this->transition($course, 'draft', $reason, $approver);
    }

    /**
     * Publish the course's current content as a new immutable version. With catalogue approval on,
     * the course must be approved first. A future effective date schedules it.
     */
    public function publish(Course $course, ?User $actor = null, CarbonInterface|string|null $effectiveFrom = null): CourseVersion
    {
        $actor ??= auth()->user();
        if (config('peopleos.learning.require_catalogue_approval', true) && $course->status !== 'approved') {
            throw new RuntimeException('This course must be approved by a second person (learning.publish) before it is published.');
        }
        if ($course->status === 'archived' || $course->status === 'retired') {
            throw new RuntimeException('A retired or archived course cannot be published; reinstate it first.');
        }
        $from = $effectiveFrom ? Carbon::parse($effectiveFrom)->startOfDay() : ($course->effective_from ?? now()->startOfDay());
        $this->assertPrerequisitesAcyclic($course);

        return DB::transaction(function () use ($course, $actor, $from) {
            Course::query()->whereKey($course->id)->lockForUpdate()->first();
            $version = $this->snapshot($course->refresh(), $actor, $from);
            $status = $from->isFuture() ? 'scheduled' : 'published';
            $course->update(['status' => $status, 'current_version_id' => $version->id, 'effective_from' => $course->effective_from ?? $from]);
            $this->audit->record(AuditAction::Create, 'learning', $version, [], null, actor: $actor, metadata: ['event' => 'course_version_published', 'course' => $course->code, 'version' => $version->version, 'checksum' => $version->checksum]);
            LearningEvent::dispatch('learning.course.published', null, $course, ['course' => $course->title, 'version' => $version->version]);

            return $version;
        });
    }

    public function retire(Course $course, string $reason, ?User $actor = null): Course
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required to retire a course.');
        }
        $course = $this->transition($course, 'retired', $reason, $actor ?? auth()->user());
        LearningEvent::dispatch('learning.course.retired', null, $course, ['course' => $course->title]);

        return $course;
    }

    public function archive(Course $course, string $reason, ?User $actor = null): Course
    {
        return $this->transition($course, 'archived', $reason, $actor ?? auth()->user());
    }

    /** First enrolment moves a published course to active (in delivery). */
    public function markActive(Course $course): void
    {
        if ($course->status === 'published') {
            $course->update(['status' => 'active']);
        }
    }

    /** Scheduled courses whose effective date has arrived become published. */
    public function releaseScheduled(CarbonInterface|string|null $today = null): int
    {
        $today = Carbon::parse($today ?? now())->startOfDay();

        return Course::query()->where('status', 'scheduled')->whereDate('effective_from', '<=', $today)->get()
            ->each(fn (Course $c) => $c->update(['status' => 'published']))->count();
    }

    /**
     * The version new enrolments pin. Courses published before Phase 8 have none; their content is
     * snapshotted once as an implicit system version (status unchanged, no approval implied).
     */
    public function ensureVersion(Course $course): CourseVersion
    {
        if ($course->current_version_id) {
            return CourseVersion::query()->findOrFail($course->current_version_id);
        }
        if (! in_array($course->status, Course::ENROLLABLE, true)) {
            throw new RuntimeException("Course {$course->code} is not published.");
        }

        return DB::transaction(function () use ($course) {
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            if ($locked->current_version_id) {
                // A locking read: under REPEATABLE READ a plain read could miss a version another
                // transaction committed after this one's snapshot.
                return CourseVersion::query()->whereKey($locked->current_version_id)->lockForUpdate()->firstOrFail();
            }
            $version = $this->snapshot($locked, null, $locked->effective_from ?? $locked->created_at?->copy()->startOfDay() ?? now()->startOfDay());
            $locked->forceFill(['current_version_id' => $version->id])->saveQuietly();
            $course->setRawAttributes($locked->getAttributes(), true);

            return $version;
        });
    }

    private function snapshot(Course $course, ?User $actor, CarbonInterface $from): CourseVersion
    {
        $course->loadMissing(['modules', 'assessment']);
        foreach ($course->skill_outcomes ?? [] as $outcome) {
            if (empty($outcome['skill_id']) || ! is_numeric($outcome['level'] ?? null)) {
                throw new RuntimeException('Each skill outcome needs a skill and a level.');
            }
        }

        return CourseVersion::query()->create([
            'course_id' => $course->id,
            'version' => (int) CourseVersion::query()->where('course_id', $course->id)->lockForUpdate()->max('version') + 1,
            'title' => $course->title,
            'description' => $course->description,
            'delivery_mode' => $course->delivery_mode ?? $this->modeFor($course->type),
            'duration_minutes' => $course->duration_minutes,
            'learning_provider_id' => $course->learning_provider_id,
            'learning_instructor_id' => $course->learning_instructor_id,
            'curriculum' => $course->modules->map(fn ($m) => ['id' => $m->id, 'title' => $m->title, 'type' => $m->type, 'url' => $m->url, 'duration_minutes' => $m->duration_minutes, 'sort_order' => $m->sort_order])->values()->all(),
            'assessment' => $course->assessment ? ['title' => $course->assessment->title, 'passing_score' => $course->assessment->passing_score, 'questions' => $course->assessment->questions, 'time_limit_minutes' => $course->assessment->time_limit_minutes] : null,
            'skill_outcomes' => $course->skill_outcomes,
            'passing_score' => $course->passing_score,
            'attempts_allowed' => $course->attempts_allowed ?? 3,
            'validity_months' => $course->validity_months,
            'cost' => $course->cost,
            'currency' => $course->currency,
            'status' => 'published',
            'effective_from' => $from->toDateString(),
            'published_by' => $actor?->id,
            'published_at' => now(),
        ]);
    }

    /** Course prerequisites must not form a cycle (A needs B needs A). */
    public function assertPrerequisitesAcyclic(Course $course): void
    {
        $graph = Course::query()->get(['id', 'prerequisite_course_ids'])->mapWithKeys(fn (Course $c) => [$c->id => array_map('intval', $c->prerequisite_course_ids ?? [])])->all();
        $graph[$course->id] = array_map('intval', $course->prerequisite_course_ids ?? []);
        foreach ($graph[$course->id] as $prerequisite) {
            if (! array_key_exists($prerequisite, $graph)) {
                throw new RuntimeException("Prerequisite course #{$prerequisite} does not exist.");
            }
        }
        self::assertAcyclic($graph, 'Course prerequisites');
    }

    /** @param  array<int, list<int>>  $graph  node => prerequisites */
    public static function assertAcyclic(array $graph, string $what): void
    {
        $state = [];
        $visit = function (int $node) use (&$visit, &$state, $graph, $what): void {
            if (($state[$node] ?? 0) === 1) {
                throw new RuntimeException("{$what} would form a circular chain.");
            }
            if (($state[$node] ?? 0) === 2) {
                return;
            }
            $state[$node] = 1;
            foreach ($graph[$node] ?? [] as $next) {
                $visit((int) $next);
            }
            $state[$node] = 2;
        };
        foreach (array_keys($graph) as $node) {
            $visit((int) $node);
        }
    }

    private function modeFor(string $type): string
    {
        return match ($type) {
            'classroom' => 'classroom', 'virtual' => 'virtual', default => 'self_paced'
        };
    }

    private function transition(Course $course, string $to, ?string $reason, ?User $actor, array $attributes = []): Course
    {
        return DB::transaction(function () use ($course, $to, $reason, $actor, $attributes) {
            $current = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $from = $current->status;
            if (! in_array($to, Course::TRANSITIONS[$from] ?? [], true)) {
                throw new RuntimeException("A course cannot move from {$from} to {$to}.");
            }
            $course->setRawAttributes($current->getAttributes(), true);
            $course->withAuditReason($reason)->update(['status' => $to, ...$attributes]);
            $this->audit->record(AuditAction::StatusChange, 'learning', $course, [['field' => 'status', 'before' => $from, 'after' => $to]], $reason, actor: $actor);

            return $course;
        });
    }
}
