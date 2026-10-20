<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\SurveyTaskProvider;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;

/** Phase 12: no surveys yet (Phase 13). */
final class NullSurveyTaskProvider implements SurveyTaskProvider
{
    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        return collect();
    }
}
