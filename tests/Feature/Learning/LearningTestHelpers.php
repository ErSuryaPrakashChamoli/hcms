<?php

use App\Domain\Learning\Models\Course;

/** A published e-learning course with two text modules and (optionally) a two-question quiz. */
function publishedCourse(array $overrides = [], bool $withQuiz = true): Course
{
    $course = Course::create(array_merge(['title' => 'POSH awareness', 'code' => 'POSH', 'type' => 'elearning', 'category' => 'compliance', 'is_mandatory' => true, 'validity_months' => 12, 'attempts_allowed' => 2, 'status' => 'published'], $overrides));
    $course->modules()->create(['title' => 'Policy', 'type' => 'text', 'content' => '...', 'sort_order' => 10]);
    $course->modules()->create(['title' => 'Cases', 'type' => 'text', 'content' => '...', 'sort_order' => 20]);

    if ($withQuiz) {
        $course->assessments()->create(['title' => 'Quiz', 'passing_score' => 50, 'questions' => [
            ['question' => 'Q1', 'options' => ['a', 'b'], 'answer' => 1, 'marks' => 1],
            ['question' => 'Q2', 'options' => ['a', 'b'], 'answer' => 0, 'marks' => 1],
        ]]);
    }

    return $course->refresh();
}
