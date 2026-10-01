<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Models\AssessmentAttempt;
use App\Domain\Learning\Models\LearningEnrolment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Grades attempts against the assessment of the course version the learner is enrolled on (Phase 8:
 * a later change to the live assessment never changes how an enrolment is graded), closes the
 * enrolment on pass, or fails it when attempts run out. Attempts are immutable once submitted.
 */
final class Assessments
{
    public function __construct(private readonly Learning $learning, private readonly Catalogue $catalogue) {}

    /** @param  array<int, int|null>  $answers  question index => chosen option index */
    public function submit(LearningEnrolment $enrolment, array $answers): AssessmentAttempt
    {
        $course = $enrolment->course()->with('assessment')->firstOrFail();
        $version = $enrolment->course_version_id ? $enrolment->courseVersion()->firstOrFail() : $this->catalogue->ensureVersion($course);
        $assessment = $version->assessment;

        if ($assessment === null || $course->assessment === null) {
            throw new RuntimeException('This course has no assessment.');
        }

        $questions = array_values($assessment['questions'] ?? []);
        $total = max(1, (int) collect($questions)->sum(fn ($q) => (int) ($q['marks'] ?? 1)));
        $earned = 0;
        foreach ($questions as $i => $q) {
            if (array_key_exists($i, $answers) && $answers[$i] !== null && (int) $answers[$i] === (int) $q['answer']) {
                $earned += (int) ($q['marks'] ?? 1);
            }
        }
        $score = round($earned / $total * 100, 2);
        $passing = $assessment['passing_score'] ?? $version->passing_score ?? 70;
        $passed = $score >= $passing;
        $allowed = $version->attempts_allowed ?? $course->attempts_allowed;

        return DB::transaction(function () use ($enrolment, $course, $answers, $score, $passed, $allowed) {
            $current = LearningEnrolment::query()->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();
            if (! $current->isOpen()) {
                throw new RuntimeException('This enrolment is closed.');
            }
            if ($current->attempts >= $allowed) {
                throw new RuntimeException('No attempts left.');
            }
            $enrolment->setRawAttributes($current->getAttributes(), true);
            $this->learning->start($enrolment);
            $attempt = AssessmentAttempt::create([
                'assessment_id' => $course->assessment->id, 'learning_enrolment_id' => $enrolment->id, 'employee_id' => $enrolment->employee_id,
                'answers' => $answers, 'score' => $score, 'passed' => $passed, 'submitted_at' => now(),
            ]);

            $enrolment->update(['attempts' => $enrolment->attempts + 1, 'score' => max((float) ($enrolment->score ?? 0), $score)]);

            if ($passed) {
                $this->learning->recomputeProgress($enrolment->refresh());
                if ($enrolment->refresh()->isOpen() && (float) $enrolment->progress >= 100 && ! $course->isInstructorLed()) {
                    $this->learning->complete($enrolment, $score);
                }
            } elseif ($enrolment->refresh()->attempts >= $allowed) {
                $this->learning->fail($enrolment);
            }

            return $attempt;
        });
    }
}
