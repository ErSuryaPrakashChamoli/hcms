<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 13: a reference from a campaign to a survey, announcement, Knowledge Base article or HR service — never a copy. */
#[Fillable(['tenant_id', 'campaign_id', 'item_type', 'item_id', 'position'])]
class CampaignItem extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPES = ['survey' => 'Survey', 'announcement' => 'Announcement', 'article' => 'Knowledge Base article', 'service' => 'HR service'];

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EngagementCampaign::class, 'campaign_id');
    }
}
