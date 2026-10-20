<?php

namespace App\Domain\Talent\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9: effective-dated pool membership. Added explicitly with a reason; ended with a reason;
 * never edited or deleted. One active membership per pool and employee (active_key is unique).
 */
#[Fillable(['tenant_id', 'talent_pool_id', 'employee_id', 'effective_from', 'effective_to', 'status', 'active_key', 'reason', 'added_by', 'ended_by', 'end_reason'])]
class TalentPoolMembership extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->active_key = $m->status === 'active' ? $m->talent_pool_id.':'.$m->employee_id : null);
        static::updating(function (self $m) {
            if ($m->getRawOriginal('status') !== 'active' || array_diff(array_keys($m->getDirty()), ['status', 'effective_to', 'active_key', 'ended_by', 'end_reason', 'updated_at']) !== []) {
                throw new \RuntimeException('Pool membership is history: it is ended with a reason, never edited.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Pool membership is ended, never deleted.'));
    }

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function auditModule(): string
    {
        return 'talent';
    }

    public function auditLabel(): string
    {
        return 'Talent pool membership';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['reason', 'end_reason'];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(TalentPool::class, 'talent_pool_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
