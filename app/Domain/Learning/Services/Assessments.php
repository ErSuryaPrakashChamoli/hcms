<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Models\AssessmentAttempt;
use App\Domain\Learning\Models\LearningEnrolment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Grades attempts and closes the enrolment on pass, or fails it when attempts run out. */
final class Assessments
{
    public function __construct(private readonly Learning $learning) {}

    /** @param  array<int, int|null>  $answers  question index => chosen option index */
    public function submit(LearningEnrolment $enrolment, array $answers): AssessmentAttempt
    {
        $course = $enrolment->course()->with('assessment')->firstOrFail();
        $assessment = $course->assessment;

        if ($assessment === null) {
            throw new RuntimeException('This course has no assessment.');
        }
        if (! $enrolment->isOpen()) {
            throw new RuntimeException('This enrolment is closed.');
        }
        if ($enrolment->attempts >= $course->attempts_allowed) {
            throw new RuntimeException('No attempts left.');
        }

        $questions = array_values($assessment->questions ?? []);
        $total = max(1, $assessment->totalMarks());
        $earned = 0;
        foreach ($questions as $i => $q) {
            if (array_key_exists($i, $answers) && $answers[$i] !== null && (int) $answers[$i] === (int) $q['answer']) {
                $earned += (int) ($q['marks'] ?? 1);
            }
        }
        $score = round($earned / $total * 100, 2);
        $passing = $assessment->passing_score ?? $course->passing_score ?? 70;
        $passed = $score >= $passing;

        return DB::transaction(function () use ($enrolment, $assessment, $answers, $score, $passed, $course) {
            $this->learning->start($enrolment);
            $attempt = AssessmentAttempt::create([
                'assessment_id' => $assessment->id, 'learning_enrolment_id' => $enrolment->id, 'employee_id' => $enrolment->employee_id,
                'answers' => $answers, 'score' => $score, 'passed' => $passed, 'submitted_at' => now(),
            ]);

            $enrolment->update(['attempts' => $enrolment->attempts + 1, 'score' => max((float) ($enrolment->score ?? 0), $score)]);

            if ($passed) {
                $this->learning->recomputeProgress($enrolment->refresh());
                if ($enrolment->refresh()->isOpen() && (float) $enrolment->progress >= 100 && ! $course->isInstructorLed()) {
                    $this->learning->complete($enrolment, $score);
                }
            } elseif ($enrolment->refresh()->attempts >= $course->attempts_allowed) {
                $this->learning->fail($enrolment);
            }

            return $attempt;
        });
    }
}
