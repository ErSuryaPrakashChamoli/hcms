<?php

namespace App\Domain\Engagement\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Phase 13: confidential mode only — the restricted link from a confidential survey response or
 * feedback item to its author. It is read only through ConfidentialIdentities::reveal (permission,
 * reason, audit). Never used for anonymous content, never in analytics. No timestamps.
 */
#[Fillable(['tenant_id', 'subject_type', 'subject_id', 'employee_id'])]
class EngagementIdentity extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Confidential identities are never changed.'));
    }
}
