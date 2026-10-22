<?php

namespace App\Domain\Employment\Policies;

use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Imports are high risk: one permission for the whole pipeline, tenant-bound by the model scope. A salary
 * upload holds pay, so only its uploader sees it, and only while allowed to prepare compensation cycles.
 */
class EmployeeImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employee.import');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allowed($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employee.import');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allowed($user, $model) && ! $model->getAttribute('imported_at');
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    private function allowed(User $user, Model $model): bool
    {
        if ($model->getAttribute('type') === SalaryImports::TYPE) {
            return SalaryImports::allowed($user) && (int) $model->getAttribute('uploaded_by') === (int) $user->getKey();
        }

        return $user->hasPermission('employee.import');
    }
}
