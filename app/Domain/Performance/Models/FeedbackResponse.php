<?php

namespace App\Domain\Performance\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HROne setup parity HP-8h: the content side of a 360 answer, about the assessed employee (employee_id), from one source.
 * Like a survey response (Phase 13) it has a random key, no timestamps and — when its source is anonymous — no link to the
 * nomination, the reviewer or the user, so it cannot be traced back to who gave it. Not Auditable: an automatic audit
 * would stamp the reviewer. Never changed or deleted. Read only through FeedbackRoundResults.
 */
#[Fillable(['tenant_id', 'feedback_round_id', 'feedback_participant_id', 'employee_id', 'relation', 'feedback_nomination_id', 'answers', 'score'])]
class FeedbackResponse extends Model
{
    use BelongsToTenant;
    use ScopedByEmployee;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (self $response): void {
            // A random (v4) key: never ordered by time, so the order of answers says nothing about who answered.
            $response->id ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new RuntimeException('A 360 feedback answer is never changed.'));
        static::deleting(fn () => throw new RuntimeException('360 feedback answers are never deleted.'));
    }

    protected function casts(): array
    {
        return ['answers' => 'array', 'score' => 'decimal:2'];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(FeedbackRound::class, 'feedback_round_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(FeedbackParticipant::class, 'feedback_participant_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function nomination(): BelongsTo
    {
        return $this->belongsTo(FeedbackNomination::class, 'feedback_nomination_id');
    }
}
