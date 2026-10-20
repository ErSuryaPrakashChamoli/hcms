<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Engagement\Concerns\HasRandomUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Phase 13: the content side of a survey response. It has:
 * - a random UUID key;
 * - no timestamps;
 * - an employee only for IDENTIFIED surveys;
 * - no reference to a participation, user, token or IP.
 *
 * Confidential authorship lives only in engagement_identities, and anonymous authorship nowhere.
 * Locked once submitted: a correction (identified / confidential only) supersedes it, and no row is ever
 * deleted. Not Auditable: an automatic audit would stamp the respondent.
 */
#[Fillable(['tenant_id', 'survey_version_id', 'employee_id', 'group_key', 'period_key', 'status', 'active_key', 'supersedes_id', 'submitted_on', 'idempotency_key'])]
class SurveyResponse extends Model
{
    use BelongsToTenant, HasRandomUuid;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (self $r): void {
            if (array_diff(array_keys($r->getDirty()), ['status', 'active_key']) !== [] || $r->getRawOriginal('status') !== 'submitted') {
                throw new RuntimeException('A submitted survey response is locked; a correction supersedes it.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('Survey responses are never deleted.'));
    }

    protected function casts(): array
    {
        return ['submitted_on' => 'date', 'active_key' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SurveyVersion::class, 'survey_version_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class, 'response_id');
    }
}
