<?php

namespace App\Domain\Compensation\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 11: one compensation reminder per kind, subject and day (derived de-duplication rows). */
#[Fillable(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'reminded_on'])]
class CompensationReminderLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['reminded_on' => 'date'];
    }
}
