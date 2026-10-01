<?php

namespace App\Domain\Talent\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 9: one talent / succession reminder per kind, subject and day. */
#[Fillable(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'reminded_on'])]
class TalentReminderLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['reminded_on' => 'date'];
    }
}
