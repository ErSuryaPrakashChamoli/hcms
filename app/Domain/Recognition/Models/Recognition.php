<?php

namespace App\Domain\Recognition\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\Letter;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use RuntimeException;

/**
 * HROne setup parity HP-9c: one badge given to one employee (the recognitions ledger). Made only by
 * Recognitions::give(); never changed or deleted. HR may withdraw it once (withdrawn_at / by / reason), which takes it
 * off the wall, the quota and the counts. The message is the giver's own words, so it is kept out of the audit trail.
 */
#[Fillable(['tenant_id', 'badge_id', 'employee_id', 'giver_user_id', 'giver_employee_id', 'message', 'visibility', 'attachments', 'withdrawn_at', 'withdrawn_by', 'withdraw_reason'])]
class Recognition extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    /** The only columns that may change after a recognition is given (its withdrawal, once). */
    private const WITHDRAWAL = ['withdrawn_at', 'withdrawn_by', 'withdraw_reason', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $recognition): void {
            if (array_diff(array_keys($recognition->getDirty()), self::WITHDRAWAL) !== [] || $recognition->getRawOriginal('withdrawn_at') !== null) {
                throw new RuntimeException('A recognition is never changed; HR may withdraw it once.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('A recognition is never deleted; HR may withdraw it.');
        });
    }

    protected function casts(): array
    {
        return ['attachments' => 'array', 'withdrawn_at' => 'datetime'];
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'message'];
    }

    public function auditModule(): string
    {
        return 'recognition';
    }

    public function auditLabel(): string
    {
        return 'Recognition '.$this->reference();
    }

    /** "BDG-000042": quoted on certificates and LinkedIn. */
    public function reference(): string
    {
        return 'BDG-'.str_pad((string) $this->getKey(), 6, '0', STR_PAD_LEFT);
    }

    public function isWithdrawn(): bool
    {
        return ($this->getAttributes()['withdrawn_at'] ?? null) !== null;
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    /** The employee who received the badge. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'giver_user_id');
    }

    public function giverEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'giver_employee_id');
    }

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    /** The certificate letter issued with the badge, if its badge maps one (Letters, source = this recognition). */
    public function certificate(): MorphOne
    {
        return $this->morphOne(Letter::class, 'source');
    }
}
