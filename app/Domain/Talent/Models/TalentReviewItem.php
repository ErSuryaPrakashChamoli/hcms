<?php

namespace App\Domain\Talent\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 9: one employee in a talent review — a person's recorded decision and reason. Decided items and completed sessions are read-only. */
#[Fillable(['tenant_id', 'talent_review_session_id', 'employee_id', 'target_designation_id', 'critical_position_id', 'status', 'decision', 'reason', 'reviewed_by', 'reviewed_at'])]
class TalentReviewItem extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'pending'];

    protected static function booted(): void
    {
        $guard = function (self $item) {
            $status = TalentReviewSession::query()->whereKey($item->talent_review_session_id)->value('status');
            if (in_array($status, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException('A completed or cancelled talent review is read-only.');
            }
        };
        static::saving($guard);
        static::deleting($guard);
        static::updating(function (self $item) {
            if ($item->getRawOriginal('status') === 'decided') {
                throw new \RuntimeException('A recorded talent review decision is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'talent';
    }

    public function auditLabel(): string
    {
        return 'Talent review item #'.$this->id;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['decision', 'reason'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TalentReviewSession::class, 'talent_review_session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
