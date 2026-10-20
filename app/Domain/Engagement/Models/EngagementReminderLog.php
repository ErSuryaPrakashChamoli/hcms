<?php

namespace App\Domain\Engagement\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 13: one invitation / reminder / closing notice per kind, subject and bucket — the idempotency guard. Derived de-duplication rows. */
#[Fillable(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'bucket'])]
class EngagementReminderLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;
}
