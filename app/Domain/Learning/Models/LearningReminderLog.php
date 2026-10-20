<?php

namespace App\Domain\Learning\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 8: one learning reminder per kind, subject and day (reminder runs are idempotent). */
#[Fillable(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'reminded_on'])]
class LearningReminderLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['reminded_on' => 'date'];
    }
}
