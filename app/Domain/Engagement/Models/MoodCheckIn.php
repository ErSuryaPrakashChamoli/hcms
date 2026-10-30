<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Engagement\Concerns\HasRandomUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne setup parity HP-9f: the content side of a mood check-in — a random UUID key, no timestamps, the period and the
 * group key, the mood (1–5), the reasons and the comment. An employee and a date only for IDENTIFIED check-ins;
 * confidential authorship lives only in engagement_identities and anonymous authorship nowhere. Written only by
 * MoodCheckIns and read only through MoodAnalytics (small groups suppressed). Never changed or deleted. Not Auditable.
 */
#[Fillable(['tenant_id', 'period_key', 'group_key', 'mood', 'reason_ids', 'comment', 'privacy', 'employee_id', 'checked_in_on'])]
class MoodCheckIn extends Model
{
    use BelongsToTenant, HasRandomUuid;

    protected $table = 'mood_checkins';

    public $timestamps = false;

    public const MOODS = [1 => 'Very unhappy', 2 => 'Unhappy', 3 => 'Okay', 4 => 'Happy', 5 => 'Very happy'];

    public const PRIVACY = ['anonymous' => 'Anonymous', 'confidential' => 'Confidential', 'identified' => 'Identified'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Mood check-ins are never changed.'));
        static::deleting(fn () => throw new RuntimeException('Mood check-ins are never deleted.'));
    }

    protected function casts(): array
    {
        return ['mood' => 'integer', 'reason_ids' => 'array', 'checked_in_on' => 'date'];
    }
}
