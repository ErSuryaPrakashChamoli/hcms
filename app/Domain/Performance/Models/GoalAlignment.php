<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity GO-4 (matrix 27 §27.2.3 #7–9, HROne's linked-people table): one period during which a goal was aligned
 * ("linked") to a parent goal — linked on, unlinked on (null while still linked), by whom. Written only by the Goal model
 * whenever `parent_id` is set, changed or cleared, by any write path; never deleted (re-linking opens a new row, so the
 * history keeps both — HROne's "unlinked cannot be linked again" is deliberately not copied).
 */
#[Fillable(['tenant_id', 'goal_id', 'parent_id', 'linked_at', 'unlinked_at', 'linked_by', 'unlinked_by'])]
class GoalAlignment extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \RuntimeException('Goal alignment history is never deleted.'));
    }

    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'unlinked_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Goal '.$this->goal_id.' aligned to goal '.$this->parent_id;
    }

    /** The aligned (child) goal. */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /** The goal it is (or was) aligned to. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'parent_id');
    }

    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    public function unlinker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlinked_by');
    }

    public function isLinked(): bool
    {
        return $this->unlinked_at === null;
    }
}
