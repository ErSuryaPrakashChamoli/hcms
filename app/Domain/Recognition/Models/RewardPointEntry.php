<?php

namespace App\Domain\Recognition\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollRun;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne setup parity HP-9d: one row of the reward points ledger. Written only by RewardPoints; never changed or deleted,
 * except that a redemption paid through payroll records its run once (payroll_run_id, settled_at). Points are signed: a
 * credit adds, a debit subtracts. Wallets: `budget` (the organisation's points, no employee), `giving` (points an employee
 * may give colleagues) and `earned` (points an employee received). The giver's words (note) stay out of the audit trail.
 */
#[Fillable(['tenant_id', 'employee_id', 'wallet', 'kind', 'points', 'amount', 'currency', 'cycle_key', 'source_type', 'source_id', 'counterpart_employee_id', 'note', 'meta', 'dedupe_key', 'payroll_run_id', 'settled_at', 'created_by'])]
class RewardPointEntry extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const WALLETS = ['budget' => 'Points budget', 'giving' => 'Points to give', 'earned' => 'Points received'];

    public const KINDS = [
        'deposit' => 'Budget deposit', 'credit' => 'Cycle credit', 'credit_funding' => 'Credited to employees', 'lapse' => 'Unused points lapsed',
        'lapse_return' => 'Lapsed points returned', 'award_given' => 'Points given', 'award_received' => 'Points received', 'badge_award' => 'Badge points',
        'badge_funding' => 'Badge points paid', 'redeem' => 'Redeemed', 'redeem_reversal' => 'Redemption cancelled', 'expire' => 'Points expired', 'adjust' => 'Adjustment',
    ];

    /** The only columns that change after a row is written (a payroll redemption's settlement, once). */
    private const SETTLEMENT = ['payroll_run_id', 'settled_at', 'updated_at'];

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            if (! array_key_exists((string) $entry->wallet, self::WALLETS) || ! array_key_exists((string) $entry->kind, self::KINDS)) {
                throw new RuntimeException('Unknown reward points wallet or kind.');
            }
            if (($entry->wallet === 'budget') !== ($entry->employee_id === null)) {
                throw new RuntimeException('Budget rows carry no employee; every other row belongs to one.');
            }
        });
        static::updating(function (self $entry): void {
            if (array_diff(array_keys($entry->getDirty()), self::SETTLEMENT) !== [] || $entry->getRawOriginal('payroll_run_id') !== null) {
                throw new RuntimeException('The reward points ledger is append-only.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('The reward points ledger is append-only.');
        });
    }

    protected function casts(): array
    {
        return ['points' => 'integer', 'amount' => 'decimal:2', 'meta' => 'array', 'settled_at' => 'datetime'];
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'note'];
    }

    public function auditModule(): string
    {
        return 'recognition';
    }

    public function auditLabel(): string
    {
        return 'Reward points '.(self::KINDS[$this->kind] ?? $this->kind).' #'.$this->getKey();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'counterpart_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
