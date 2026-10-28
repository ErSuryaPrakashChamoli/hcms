<?php

namespace App\Domain\Employment\Policies;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Imports are high risk: one permission for the whole pipeline, tenant-bound by the model scope. A salary
 * upload holds pay and a bank details upload holds account numbers, so only the uploader sees either, and
 * only while allowed to make those changes. A monthly attendance upload also needs attendance.manage.
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
        $uploader = (int) $model->getAttribute('uploaded_by') === (int) $user->getKey();

        return match ($model->getAttribute('type')) {
            SalaryImports::TYPE => SalaryImports::allowed($user) && $uploader,
            BankAccountImports::TYPE => BankAccountImports::allowed($user) && $uploader,
            AttendanceSheetImports::TYPE => AttendanceSheetImports::allowed($user),
            default => $user->hasPermission('employee.import'),
        };
    }
}
