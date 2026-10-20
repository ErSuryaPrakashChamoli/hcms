<?php

namespace App\Domain\Learning\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningProgramParticipant;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Skills\Services\SkillProfiles;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8: completion is a finalized record, distinct from progress reaching 100%. The enrolment
 * row is locked, a second completion of the same enrolment is refused (and impossible: unique
 * enrolment + sequence), the course version actually completed is recorded, and corrections are
 * new records — the original is only ever marked superseded.
 */
final class Completions
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
        private readonly Certificates $certificates,
        private readonly Catalogue $catalogue,
        private readonly SkillProfiles $skills,
    ) {}

    /** @param  array{grade?: ?string, attendance?: ?string, evidence?: ?string}  $details */
    public function finalize(LearningEnrolment $enrolment, ?float $score = null, ?User $actor = null, array $details = []): LearningCompletion
    {
        $actor ??= auth()->user();
        foreach (['grade' => 'grades', 'attendance' => 'attendance'] as $field => $list) {
            if (isset($details[$field]) && ! array_key_exists($details[$field], config("peopleos.learning.{$list}"))) {
                throw new RuntimeException("Unknown {$field} '{$details[$field]}'.");
            }
        }
        if ($score !== null && ($score < 0 || $score > 100)) {
            throw new RuntimeException('A score is between 0 and 100.');
        }

        $completion = DB::transaction(function () use ($enrolment, $score, $actor, $details) {
            $current = LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();
            if (LearningCompletion::query()->withoutGlobalScope(AccessScope::class)->where('learning_enrolment_id', $current->id)->exists()) {
                throw new RuntimeException('This enrolment is already completed.');
            }
            if (! $current->isOpen()) {
                throw new RuntimeException('This enrolment is closed.');
            }
            $course = $current->course()->firstOrFail();
            $version = $current->course_version_id ? $current->courseVersion()->firstOrFail() : $this->catalogue->ensureVersion($course);
            $employee = $current->employee()->withoutGlobalScope(AccessScope::class)->with('person')->firstOrFail();
            $score ??= $current->score === null ? null : (float) $current->score;

            $completion = LearningCompletion::query()->create([
                'employee_id' => $employee->id, 'learning_enrolment_id' => $current->id, 'sequence' => 1,
                'course_id' => $course->id, 'course_version_id' => $version->id,
                'completed_at' => now(), 'completed_by' => $actor?->id, 'score' => $score,
                'grade' => $details['grade'] ?? null, 'attendance' => $details['attendance'] ?? null, 'evidence' => $details['evidence'] ?? null,
                'hours' => $version->hours(), 'learning_provider_id' => $version->learning_provider_id, 'learning_instructor_id' => $version->learning_instructor_id,
                'status' => 'final',
            ]);
            $expires = $version->validity_months ? now()->addMonths($version->validity_months)->startOfDay() : null;
            $enrolment->setRawAttributes($current->getAttributes(), true);
            $enrolment->update(['status' => 'completed', 'progress' => 100, 'score' => $score, 'completed_at' => $completion->completed_at, 'expires_on' => $expires, 'course_version_id' => $version->id]);

            if ($version->validity_months) {
                $this->certificates->issueForCompletion($completion, $version, $employee, $actor, $current->is_mandatory);
            }
            foreach ($version->skill_outcomes ?? [] as $outcome) {
                $this->skills->recordFromLearning($employee, (int) $outcome['skill_id'], (float) $outcome['level'], $completion, $actor);
            }

            $this->audit->record(AuditAction::Create, 'learning', $current, [['field' => 'completion', 'before' => null, 'after' => 'final']], null, actor: $actor, metadata: ['event' => 'completion_finalized', 'completion_id' => $completion->id, 'course_version_id' => $version->id]);
            $this->timeline->record($employee, 'learning', "Completed: {$version->title} (v{$version->version})", now(), null, $enrolment, ['score' => $score]);
            LearningEvent::dispatch('learning.completed', $employee, $enrolment, ['course' => $version->title, 'score' => $score, 'expires_on' => $expires?->toDateString()]);

            return $completion;
        });

        if ($enrolment->learning_program_participant_id) {
            $participant = LearningProgramParticipant::query()->withoutGlobalScope(AccessScope::class)->find($enrolment->learning_program_participant_id);
            if ($participant) {
                app(Programs::class)->evaluate($participant, $actor);
            }
        }

        return $completion;
    }

    /**
     * Correct a finalized completion: a new record (next sequence) carrying the corrected values and
     * the reason; the original stays as it was, marked superseded. Needs learning.manage.
     *
     * @param  array{score?: ?float, grade?: ?string, attendance?: ?string, evidence?: ?string, completed_at?: string}  $changes
     */
    public function correct(LearningCompletion $original, array $changes, string $reason, User $actor): LearningCompletion
    {
        if (! $actor->hasPermission('learning.manage')) {
            throw new RuntimeException('Correcting a completion needs learning.manage.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A correction needs a reason.');
        }
        $changes = array_intersect_key($changes, array_flip(['score', 'grade', 'attendance', 'evidence', 'completed_at']));
        if ($changes === []) {
            throw new RuntimeException('Nothing to correct.');
        }

        return DB::transaction(function () use ($original, $changes, $reason, $actor) {
            $current = LearningCompletion::query()->withoutGlobalScope(AccessScope::class)->whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'final') {
                throw new RuntimeException('Only the current completion record can be corrected.');
            }
            $attributes = collect($current->getAttributes())->except(['id', 'created_at', 'status', 'sequence', 'corrects_completion_id', 'correction_reason', 'tenant_id'])->all();
            $correction = LearningCompletion::query()->create([
                ...$attributes, ...$changes,
                'sequence' => $current->sequence + 1, 'status' => 'final',
                'corrects_completion_id' => $current->id, 'correction_reason' => $reason, 'completed_by' => $actor->id,
            ]);
            $current->update(['status' => 'superseded']);
            $this->audit->record(AuditAction::Update, 'learning', $correction, collect($changes)->map(fn ($v, $k) => ['field' => $k, 'before' => $current->getAttribute($k), 'after' => $v])->values()->all(), $reason, actor: $actor, metadata: ['event' => 'completion_corrected', 'corrects' => $current->id]);

            return $correction;
        });
    }

    public function finalizeProgram(LearningProgramParticipant $participant, ?User $actor = null): LearningCompletion
    {
        return DB::transaction(function () use ($participant, $actor) {
            $current = LearningProgramParticipant::query()->withoutGlobalScope(AccessScope::class)->whereKey($participant->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'enrolled') {
                throw new RuntimeException('This program participation is closed.');
            }
            $version = $current->version()->with('program')->firstOrFail();
            $completion = LearningCompletion::query()->create([
                'employee_id' => $current->employee_id, 'learning_program_participant_id' => $current->id, 'sequence' => 1,
                'learning_program_version_id' => $version->id, 'completed_at' => now(), 'completed_by' => $actor?->id, 'status' => 'final',
            ]);
            $participant->setRawAttributes($current->getAttributes(), true);
            $participant->update(['status' => 'completed', 'completed_at' => now()]);
            $employee = $current->employee()->withoutGlobalScope(AccessScope::class)->with('person')->firstOrFail();
            if ($version->issues_certificate) {
                $this->certificates->issueForProgram($completion, $version, $employee, $actor);
            }
            $this->audit->record(AuditAction::Create, 'learning', $participant, [['field' => 'completion', 'before' => null, 'after' => 'final']], null, actor: $actor, metadata: ['event' => 'program_completed', 'completion_id' => $completion->id]);
            LearningEvent::dispatch('learning.program.completed', $employee, $participant, ['program' => $version->program?->name]);

            return $completion;
        });
    }
}
