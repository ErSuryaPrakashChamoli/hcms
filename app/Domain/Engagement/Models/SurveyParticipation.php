<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 13: the identity side of a survey — who was eligible (the audience snapshot at opening) and
 * how far they got (invited / opened / submitted / expired). Dates only, with no time of day and no
 * timestamps, and no key shared with the response content (docs/architecture/
 * engagement-communication.md §3). Derived tracking rows, deliberately not Auditable: an automatic
 * audit would stamp the submitting user and the exact time.
 */
#[Fillable(['tenant_id', 'survey_version_id', 'employee_id', 'group_key', 'status', 'invited_on', 'opened_on', 'submitted_on', 'expired_on', 'reminders_sent'])]
class SurveyParticipation extends Model
{
    use BelongsToTenant;
    use ScopedByEmployee;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['invited_on' => 'date', 'opened_on' => 'date', 'submitted_on' => 'date', 'expired_on' => 'date', 'reminders_sent' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'survey_version_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
