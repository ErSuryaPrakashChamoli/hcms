<?php

namespace App\Domain\Workforce\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 10: one workforce reminder per kind, subject and day (derived de-duplication rows). */
#[Fillable(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'reminded_on'])]
class WorkforceReminderLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['reminded_on' => 'date'];
    }
}
