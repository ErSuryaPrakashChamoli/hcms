<?php

namespace App\Domain\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 7: one reminder per kind, subject and day — makes reminder runs idempotent (no noisy loops). */
#[Fillable(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'reminded_on'])]
class PerformanceReminderLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['reminded_on' => 'date'];
    }
}
