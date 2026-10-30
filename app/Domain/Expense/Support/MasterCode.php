<?php

namespace App\Domain\Expense\Support;

use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use Illuminate\Database\Eloquent\Model;

/**
 * Expense masters are identified by a code (upper case, unique per tenant). Like other masters, the code is fixed once
 * created: reports, exports and later payroll lines name the record by it.
 */
trait MasterCode
{
    protected static function bootMasterCode(): void
    {
        static::saving(function (Model $model): void {
            $model->setAttribute('code', strtoupper(trim((string) $model->getAttribute('code'))));
            if ($model->exists && strtoupper((string) $model->getRawOriginal('code')) !== $model->getAttribute('code')) {
                throw new ExpenseRuleViolation('The code cannot change once created: reports and payroll lines name the record by it.');
            }
        });
    }
}
