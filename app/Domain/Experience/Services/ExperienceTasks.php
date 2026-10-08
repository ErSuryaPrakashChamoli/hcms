<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Contracts\SurveyTaskProvider;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Phase 12: the unified task list (My HR → Tasks). It asks each configured domain source
 * (`peopleos.experience.task_sources`) plus the survey hook, and sorts by due date. A failing source is
 * reported and skipped, so one domain never blanks the list.
 */
final class ExperienceTasks
{
    /** @return Collection<int, ExperienceTask> */
    public function for(User $user, ?Employee $employee): Collection
    {
        $sources = [...array_map(fn (string $class) => app($class), config('peopleos.experience.task_sources', [])), app(SurveyTaskProvider::class)];

        return collect($sources)->flatMap(function (ExperienceTaskSource $source) use ($user, $employee) {
            try {
                return $source->tasksFor($user, $employee);
            } catch (Throwable $e) {
                report($e);

                return collect();
            }
        })->sortBy(fn (ExperienceTask $t) => $t->dueAt?->timestamp ?? PHP_INT_MAX)->values();
    }
}
