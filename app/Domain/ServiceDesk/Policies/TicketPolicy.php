<?php

namespace App\Domain\ServiceDesk\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use Illuminate\Database\Eloquent\Model;

/** Phase 12: HR requests / cases are authorised by CaseAccess (the same rules as the queries and the API). */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('servicedesk.view') || $user->hasPermission('servicedesk.agent') || $user->hasPermission('servicedesk.request')
            || $user->hasPermission('servicedesk.team') || $user->hasPermission('servicedesk.confidential');
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof Ticket && app(CaseAccess::class)->canView($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('servicedesk.request') || $user->hasPermission('servicedesk.agent');
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof Ticket && app(CaseAccess::class)->canWork($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public static function isOwn(User $user, Model $model): bool
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $user->id)->where('id', $model->getAttribute('employee_id'))->exists();
    }
}
