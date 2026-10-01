<?php

namespace App\Domain\Talent\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 9: a structured talent review. draft → in progress → completed (read-only) or cancelled. */
#[Fillable(['tenant_id', 'name', 'organisation_node_id', 'facilitator_user_id', 'participants', 'scheduled_for', 'status', 'workflow_instance_id', 'decision_summary', 'completed_by', 'completed_at', 'lock_version', 'created_by'])]
class TalentReviewSession extends Model
{
    use Auditable, BelongsToTenant;

    public const TRANSITIONS = ['draft' => ['in_progress', 'cancelled'], 'in_progress' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => []];

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::updating(function (self $s) {
            $from = $s->getRawOriginal('status');
            if (in_array($from, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException('A completed or cancelled talent review is read-only.');
            }
            if ($s->isDirty('status') && ! in_array($s->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("A talent review cannot move from {$from} to {$s->status}.");
            }
            if (! $s->isDirty('lock_version')) {
                $s->lock_version = (int) $s->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Talent reviews are cancelled, never deleted.'));
    }

    protected function casts(): array
    {
        return ['participants' => 'array', 'scheduled_for' => 'date', 'completed_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'talent';
    }

    public function auditLabel(): string
    {
        return 'Talent review: '.$this->name;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['decision_summary'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TalentReviewItem::class);
    }

    public function organisationNode(): BelongsTo
    {
        return $this->belongsTo(OrganisationNode::class);
    }
}
