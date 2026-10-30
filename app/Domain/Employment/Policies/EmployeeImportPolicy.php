<?php

namespace App\Domain\Employment\Policies;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Imports are high risk: one permission for the whole pipeline, tenant-bound by the model scope. A salary
 * upload holds pay and a bank details upload holds account numbers, so only the uploader sees either, and
 * only while allowed to make those changes. A monthly attendance upload also needs attendance.manage.
 * HROne setup parity P2: under maker-checker or workflow approval (ImportGovernance) the checker of a bank or salary
 * upload — someone allowed to make one — also sees it once it is validated.
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
        $uploader = (int) $model->getAttribute('uploaded_by') === (int) $user->getKey()
            || ($model instanceof EmployeeImport && ImportGovernance::checkerMaySee($user, $model));

        return match ($model->getAttribute('type')) {
            SalaryImports::TYPE => SalaryImports::allowed($user) && $uploader,
            BankAccountImports::TYPE => BankAccountImports::allowed($user) && $uploader,
            AttendanceSheetImports::TYPE => AttendanceSheetImports::allowed($user),
            // HROne parity uploads also need their module permission (leave.manage / attendance.manage).
            default => RowImportTypes::has((string) $model->getAttribute('type'))
                ? RowImportTypes::allowed($user, (string) $model->getAttribute('type'))
                : $user->hasPermission('employee.import'),
        };
    }
}
