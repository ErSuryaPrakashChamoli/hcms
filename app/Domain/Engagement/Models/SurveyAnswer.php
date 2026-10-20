<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Engagement\Concerns\HasRandomUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Phase 13: one answer to one question: an option (one row per selected option), a number, a date or
 * encrypted free text. Random UUID key, no timestamps, immutable, never deleted.
 */
#[Fillable(['tenant_id', 'survey_version_id', 'response_id', 'question_id', 'value_option', 'value_number', 'value_date', 'value_text'])]
class SurveyAnswer extends Model
{
    use BelongsToTenant, HasRandomUuid;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Survey answers are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Survey answers are never deleted.'));
    }

    protected function casts(): array
    {
        return ['value_number' => 'decimal:4', 'value_date' => 'date', 'value_text' => 'encrypted'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(SurveyQuestion::class, 'question_id');
    }
}
