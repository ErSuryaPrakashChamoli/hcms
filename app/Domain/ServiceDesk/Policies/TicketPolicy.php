<?php

namespace App\Domain\ServiceDesk\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Agents (servicedesk.view) see every ticket; employees see their own. */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('servicedesk.view') || $user->hasPermission('servicedesk.request');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('servicedesk.view') || $model->getAttribute('assignee_id') === $user->id || self::isOwn($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('servicedesk.request') || $user->hasPermission('servicedesk.view');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('servicedesk.view') || $model->getAttribute('assignee_id') === $user->id;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public static function isOwn(User $user, Model $model): bool
    {
        return Employee::query()->where('user_id', $user->id)->where('id', $model->getAttribute('employee_id'))->exists();
    }
}
