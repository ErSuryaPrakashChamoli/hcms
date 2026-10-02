<?php

namespace App\Domain\Experience\Contracts;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Phase 12: a domain's contribution to the unified "My tasks" list. Each domain reads its own records
 * with its own authorisation and returns references (ExperienceTask). The experience layer stores
 * nothing and never queries another domain's tables.
 */
interface ExperienceTaskSource
{
    /** @return Collection<int, ExperienceTask> */
    public function tasksFor(User $user, ?Employee $employee): Collection;
}
