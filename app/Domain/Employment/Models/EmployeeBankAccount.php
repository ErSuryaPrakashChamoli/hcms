<?php

namespace App\Domain\Employment\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'employee_id', 'account_holder_name', 'bank_name', 'branch_name', 'account_number', 'ifsc', 'account_type', 'is_primary', 'verified_at'])]
#[Hidden(['account_number'])]
class EmployeeBankAccount extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $account->account_number_last4 = mb_substr((string) $account->account_number, -4);

            if ($account->is_primary) {
                static::query()->where('employee_id', $account->employee_id)->whereKeyNot($account->getKey())->update(['is_primary' => false]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->bank_name} ••••{$this->account_number_last4}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['account_number'];
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'account_number_last4'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function maskedAccountNumber(): string
    {
        return '••••'.$this->account_number_last4;
    }
}
