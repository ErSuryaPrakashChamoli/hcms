<?php

namespace App\Domain\Performance\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Services\PerformanceRelationships;
use Illuminate\Database\Eloquent\Model;

/**
 * One-on-ones follow the employee-owned rules, and (HROne setup parity HP-8b) the employee who scheduled a meeting and
 * hosts it may also open and update it — with performance.one_on_one.scope = organisation the host need not manage
 * the other side. Being the host gives no other performance rights.
 */
class OneOnOnePolicy extends EmployeeOwnedPolicy
{
    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) || self::isHost($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) || self::isHost($user, $model);
    }

    public static function isHost(User $user, Model $model): bool
    {
        $me = app(PerformanceRelationships::class)->forUser($user);
        $attributes = $model->getAttributes();

        return $me !== null && (int) ($attributes['manager_id'] ?? 0) === (int) $me->id && (int) ($attributes['initiated_by'] ?? 0) === (int) $me->id;
    }
}
