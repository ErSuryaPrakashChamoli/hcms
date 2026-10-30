<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne setup parity BP-13 / CA-5: one version of a bank's salary-transfer file format, as data (no code per bank):
 * delimited or fixed-width, the columns in order (field, heading, width, alignment, padding, letter case, number or
 * date format, fixed text), header and footer lines with placeholders ({count}, {total}, {payment_date:d/m/Y},
 * {debit_account} …) and the line ending.
 *
 * Versioned like the statutory export layouts: a draft is edited; once active its content is frozen and checksummed, and
 * a change is a new version of the same code (activating it retires the one before). A paying bank account names the
 * layout by code, so the bank file always uses the code's active version and records which one in its export audit.
 */
#[Fillable(['tenant_id', 'code', 'version', 'name', 'status', 'specification', 'checksum', 'notes', 'created_by', 'activated_by', 'activated_at'])]
class BankFileLayout extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'retired' => 'Retired'];

    /** What a column can print: field => label. */
    public const FIELDS = [
        'sequence' => 'Row number', 'employee_code' => 'Employee code', 'beneficiary_name' => 'Beneficiary name', 'bank' => 'Employee\'s bank',
        'ifsc' => 'Employee\'s IFSC', 'account_number' => 'Employee\'s account number', 'amount' => 'Amount', 'narration' => 'Narration',
        'payment_date' => 'Payment date', 'period' => 'Pay month', 'company' => 'Company', 'debit_account' => 'Debit account number',
        'debit_ifsc' => 'Debit account IFSC', 'debit_bank_code' => 'Salary bank code', 'transfer_type' => 'Same bank / other bank', 'literal' => 'Fixed text',
    ];

    /** Placeholders in header and footer lines. */
    public const PLACEHOLDERS = ['count', 'total', 'total_whole', 'total_paise', 'payment_date', 'today', 'period', 'company', 'debit_account', 'debit_ifsc', 'debit_bank_code', 'batch'];

    /** The content frozen once a version leaves draft. */
    private const CONTENT = ['code', 'version', 'name', 'specification', 'checksum'];

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected static function booted(): void
    {
        static::saving(function (self $layout): void {
            $layout->code = strtoupper(trim((string) $layout->code));
            if ($layout->exists && $layout->getRawOriginal('status') !== 'draft' && array_intersect(array_keys($layout->getDirty()), self::CONTENT) !== []) {
                throw new RuntimeException('An active or retired bank file layout never changes; make a new version.');
            }
            $layout->checksum = self::checksumFor((string) $layout->code, (int) $layout->version, (array) $layout->specification);
        });
        static::deleting(function (self $layout): void {
            if ($layout->getRawOriginal('status') !== 'draft') {
                throw new RuntimeException('Only a draft bank file layout can be deleted; retire an active one by activating a new version.');
            }
        });
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'specification' => 'array', 'activated_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "Bank file layout {$this->code} v{$this->version}";
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** sha256 of [code, version, specification with object keys sorted at every level]. */
    public static function checksumFor(string $code, int $version, array $specification): string
    {
        return hash('sha256', json_encode([$code, $version, self::canonical($specification)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function checksumIntact(): bool
    {
        return hash_equals((string) $this->checksum, self::checksumFor((string) $this->code, (int) $this->version, (array) $this->specification));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => self::canonical($v), $value);
    }
}
