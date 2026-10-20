<?php

namespace App\Domain\Development\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8: an employee development plan — goals, skill gaps, learning, milestones and reviews.
 * Controlled transitions; a completed, cancelled or archived plan is read-only history.
 */
#[Fillable(['tenant_id', 'employee_id', 'title', 'summary', 'status', 'owner_employee_id', 'starts_on', 'target_date', 'completed_at', 'completed_by', 'private_notes', 'lock_version', 'created_by'])]
class DevelopmentPlan extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'on_hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'archived' => 'Archived'];

    public const TRANSITIONS = [
        'draft' => ['active', 'cancelled'],
        'active' => ['on_hold', 'completed', 'cancelled'],
        'on_hold' => ['active', 'cancelled'],
        'completed' => ['archived'],
        'cancelled' => ['archived'],
        'archived' => [],
    ];

    public const CLOSED = ['completed', 'cancelled', 'archived'];

    protected $attributes = ['status' => 'draft'];

    protected $hidden = ['private_notes'];

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            if ($p->starts_on && $p->target_date && $p->target_date->lt($p->starts_on)) {
                throw new \RuntimeException('A development plan cannot end before it starts.');
            }
        });
        static::updating(function (self $p) {
            $from = $p->getRawOriginal('status');
            if ($p->isDirty('status') && ! in_array($p->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("A development plan cannot move from {$from} to {$p->status}.");
            }
            if (in_array($from, self::CLOSED, true) && ! ($p->isDirty('status') && $p->status === 'archived' && array_diff(array_keys($p->getDirty()), ['status', 'updated_at', 'lock_version']) === [])) {
                throw new \RuntimeException('A closed development plan is read-only history.');
            }
            if (! $p->isDirty('lock_version')) {
                $p->lock_version = (int) $p->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(function (self $p) {
            if ($p->status !== 'draft') {
                throw new \RuntimeException('Only a draft development plan can be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'target_date' => 'date', 'completed_at' => 'datetime', 'private_notes' => 'encrypted', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'development';
    }

    public function auditLabel(): string
    {
        return 'Development plan: '.$this->title;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['private_notes'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DevelopmentPlanItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }
}
