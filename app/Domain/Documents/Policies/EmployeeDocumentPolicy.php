<?php

namespace App\Domain\Documents\Policies;

use App\Domain\Documents\Models\DocumentType;
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
            && Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $user->id)->whereKey($model->getAttribute('employee_id'))->exists()
            && self::visibleToEmployee($model);
    }

    /** HROne setup parity CA-4: a document type may be kept from the employee ("Employee can view" off). */
    private static function visibleToEmployee(Model $model): bool
    {
        $typeId = $model->getAttribute('document_type_id');
        if ($typeId === null) {
            return true;
        }
        $type = $model->relationLoaded('type') ? $model->getRelation('type') : DocumentType::query()->find($typeId);

        return $type === null || (bool) $type->employee_can_view;
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
