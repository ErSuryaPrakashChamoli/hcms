<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity HP-8b: the feedback card one side gives after a held one-on-one (performance.one_on_one.feedback_from):
 * a 1–5 rating and a comment. The row is also that person's acknowledgement of the meeting; it is written once.
 */
#[Fillable(['tenant_id', 'one_on_one_id', 'employee_id', 'role', 'rating', 'comment', 'acknowledged_at'])]
class OneOnOneFeedback extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $table = 'one_on_one_feedback';

    public const ROLES = ['initiator' => 'Initiator of the 1-on-1', 'participant' => 'Participant in the 1-on-1'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('One-on-one feedback is given once and kept as it was.'));
    }

    protected function casts(): array
    {
        return ['rating' => 'integer', 'acknowledged_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'One-on-one feedback';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['comment'];
    }

    public function oneOnOne(): BelongsTo
    {
        return $this->belongsTo(OneOnOne::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
