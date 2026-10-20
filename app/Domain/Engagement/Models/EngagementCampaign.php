<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 13: an engagement campaign groups related activity — surveys, announcements, Knowledge Base
 * articles and HR services — by reference only, with an owner, audience, dates and its own approval.
 * It duplicates none of them: each item stays owned and approved by its own module.
 */
#[Fillable(['tenant_id', 'code', 'name', 'purpose', 'owner_id', 'audience_id', 'starts_on', 'ends_on', 'status', 'prepared_by', 'submitted_at', 'approved_by', 'approved_at', 'decision_note', 'launched_at', 'completed_at', 'cancelled_at', 'cancel_reason', 'workflow_instance_id', 'idempotency_key', 'operation_id', 'lock_version'])]
class EngagementCampaign extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'draft', 'lock_version' => 0];

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->code = strtoupper(trim((string) $c->code)));
        static::deleting(fn () => throw new \RuntimeException('Campaigns are cancelled, never deleted.'));
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'launched_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return "Campaign {$this->name} ({$this->code})";
    }

    public function items(): HasMany
    {
        return $this->hasMany(CampaignItem::class, 'campaign_id')->orderBy('position');
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
