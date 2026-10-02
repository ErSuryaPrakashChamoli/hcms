<?php

namespace App\Domain\Documents\Policies;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

class EmployeeDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('document.view');
    }

    /** Document readers within scope; Phase 12: employees with document.own open their own documents. */
    public function view(User $user, Model $model): bool
    {
        return ($user->hasPermission('document.view') && app(AccessScopes::class)->allows($user, $model)) || self::isOwn($user, $model);
    }

    public static function isOwn(User $user, Model $model): bool
    {
        return $user->hasPermission('document.own')
            && Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $user->id)->whereKey($model->getAttribute('employee_id'))->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('document.upload');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('document.upload');
    }

    public function verify(User $user, Model $model): bool
    {
        return $user->hasPermission('document.verify');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('document.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('document.delete');
    }
}
