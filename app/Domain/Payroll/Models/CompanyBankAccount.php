<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne setup parity BP-13 / CA-5: a bank account a company pays salaries from (HROne's "Salary bank" and "Payment
 * bank"): the bank, its salary bank code, branch and IFSC, the debit account number (encrypted, last four kept for
 * display) and the bank file layout its transfers are written in. One account per company may be the default; the
 * bank file pays each employee from the account set on their payment mode, else the account at the employee's own bank
 * (same IFSC bank code), else the default.
 */
#[Fillable(['tenant_id', 'company_id', 'alias', 'bank_name', 'bank_code', 'branch_name', 'ifsc', 'account_number', 'bank_file_layout_code', 'is_default', 'status', 'notes'])]
#[Hidden(['account_number'])]
class CompanyBankAccount extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $attributes = ['status' => 'active', 'is_default' => false];

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $account->ifsc = filled($account->ifsc) ? strtoupper(str_replace(' ', '', (string) $account->ifsc)) : null;
            if (filled($account->ifsc) && ! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', (string) $account->ifsc)) {
                throw new RuntimeException('Give the IFSC as four letters, a zero and six letters or digits (ABCD0123456).');
            }
            if (! $account->exists || $account->isDirty('account_number')) {
                $number = str_replace([' ', "\u{00A0}"], '', (string) $account->account_number);
                if ($number === '' || mb_strlen($number) > 34) {
                    throw new RuntimeException('Give the debit account number (up to 34 letters or digits).');
                }
                $account->account_number = $number;
                $account->account_number_last4 = mb_substr($number, -4);
            }
            if ($account->is_default && $account->status === 'active') {
                static::query()->where('company_id', $account->company_id)->whereKeyNot($account->getKey())->where('is_default', true)->get()
                    ->each(fn (self $other) => $other->withAuditReason('Another account became the default')->update(['is_default' => false]));
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('A paying bank account is never deleted; mark it inactive.');
        });
    }

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'is_default' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return ($this->alias ?: $this->bank_name).' ••••'.$this->account_number_last4;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['account_number'];
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'account_number_last4'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The bank part of the IFSC (its first four letters), which identifies the bank. */
    public function bankIdentifier(): ?string
    {
        return filled($this->ifsc) ? substr((string) $this->ifsc, 0, 4) : null;
    }

    public function maskedAccountNumber(): string
    {
        return '••••'.$this->account_number_last4;
    }

    public function label(): string
    {
        return trim(($this->alias ?: $this->bank_name).' '.$this->maskedAccountNumber());
    }
}
