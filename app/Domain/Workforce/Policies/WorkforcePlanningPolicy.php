<?php

namespace App\Domain\Workforce\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Workforce\Models\WorkforcePlan;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Models\WorkforceScenario;
use Illuminate\Database\Eloquent\Model;

/**
 * Workforce plans, versions, lines and scenarios: planning users (workforce.view / plan / review /
 * approve) within organisation scope (plans are scoped on every dimension they carry). Managers
 * (workforce.team) and employees do not see plans or scenarios. Never deleted (lines change only in a
 * draft version, through the service).
 */
class WorkforcePlanningPolicy
{
    private const READERS = ['workforce.view', 'workforce.plan', 'workforce.review', 'workforce.approve'];

    public function viewAny(User $user): bool
    {
        return collect(self::READERS)->contains(fn ($p) => $user->hasPermission($p));
    }

    public function view(User $user, Model $model): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }
        if ($model instanceof WorkforceScenario) {
            return true;
        }
        $plan = $this->plan($model);

        return $plan !== null && app(AccessScopes::class)->allows($user, $plan);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('workforce.plan');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('workforce.plan') && $this->view($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    private function plan(Model $model): ?WorkforcePlan
    {
        return match (true) {
            $model instanceof WorkforcePlan => $model,
            $model instanceof WorkforcePlanVersion => WorkforcePlan::query()->withoutGlobalScope(AccessScope::class)->find($model->workforce_plan_id),
            $model instanceof WorkforcePlanLine => WorkforcePlan::query()->withoutGlobalScope(AccessScope::class)->find(WorkforcePlanVersion::query()->whereKey($model->workforce_plan_version_id)->value('workforce_plan_id')),
            default => null,
        };
    }
}
