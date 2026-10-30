<?php

namespace App\Domain\Employment\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity CA-5 (Payment bank → "restrict the values employees can enter in the bank account number field"):
 * what an account number at one bank looks like — its length and which characters it may hold. A rule names the bank by
 * the bank part of its IFSC (first four letters, e.g. HDFC) and/or by name; an account matches by IFSC first. Without a
 * matching active rule an account number is checked as before (up to 34 characters).
 */
#[Fillable(['tenant_id', 'bank_name', 'ifsc_prefix', 'min_length', 'max_length', 'allow_digits', 'allow_letters', 'allow_special', 'status', 'notes'])]
class BankAccountRule extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $attributes = ['status' => 'active', 'allow_digits' => true, 'allow_letters' => true, 'allow_special' => false];

    protected static function booted(): void
    {
        static::saving(function (self $rule): void {
            $rule->ifsc_prefix = filled($rule->ifsc_prefix) ? strtoupper(trim((string) $rule->ifsc_prefix)) : null;
            if ($rule->ifsc_prefix !== null && ! preg_match('/^[A-Z]{4}$/', $rule->ifsc_prefix)) {
                throw new \RuntimeException('The IFSC bank code is the first four letters of the IFSC, e.g. HDFC.');
            }
            if (blank($rule->bank_name) && $rule->ifsc_prefix === null) {
                throw new \RuntimeException('Name the bank or give its IFSC bank code.');
            }
            if ($rule->min_length !== null && $rule->max_length !== null && (int) $rule->min_length > (int) $rule->max_length) {
                throw new \RuntimeException('The shortest length cannot be more than the longest.');
            }
            if (! $rule->allow_digits && ! $rule->allow_letters && ! $rule->allow_special) {
                throw new \RuntimeException('Allow at least one kind of character.');
            }
        });
    }

    protected function casts(): array
    {
        return ['min_length' => 'integer', 'max_length' => 'integer', 'allow_digits' => 'boolean', 'allow_letters' => 'boolean', 'allow_special' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'employment';
    }

    public function auditLabel(): string
    {
        return 'Account number rule '.($this->ifsc_prefix ?? $this->bank_name);
    }

    /** What is wrong with an account number under this rule (null when it fits). */
    public function problemWith(string $number): ?string
    {
        $bank = $this->bank_name ?: $this->ifsc_prefix;
        $length = mb_strlen($number);
        if ($this->min_length !== null && $length < $this->min_length) {
            return "{$bank} account numbers have at least {$this->min_length} characters.";
        }
        if ($this->max_length !== null && $length > $this->max_length) {
            return "{$bank} account numbers have at most {$this->max_length} characters.";
        }
        $kinds = implode(', ', array_filter([$this->allow_digits ? 'digits' : null, $this->allow_letters ? 'letters' : null, $this->allow_special ? 'special characters' : null]));

        return match (true) {
            ! $this->allow_digits && preg_match('/\d/', $number) === 1,
            ! $this->allow_letters && preg_match('/\p{L}/u', $number) === 1,
            ! $this->allow_special && preg_match('/[^0-9\p{L}]/u', $number) === 1 => "{$bank} account numbers may hold only {$kinds}.",
            default => null,
        };
    }
}
