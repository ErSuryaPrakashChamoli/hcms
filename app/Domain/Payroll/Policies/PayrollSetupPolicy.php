<?php

namespace App\Domain\Payroll\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\BankFileLayout;
use App\Domain\Payroll\Models\CompanyBankAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity BP-13 / CA-5: paying bank accounts, bank file layouts and account number rules — read with
 * payroll.view or payroll.manage, kept with payroll.manage. A paying account is never deleted (made inactive) and only a
 * draft layout is deleted.
 */
class PayrollSetupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view') || $user->hasPermission('payroll.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.manage') && ! $model instanceof CompanyBankAccount
            && (! $model instanceof BankFileLayout || $model->getRawOriginal('status') === 'draft');
    }
}
