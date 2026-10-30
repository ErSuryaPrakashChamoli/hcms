<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne setup parity HP-8j: feedback on an initiative — asked of a colleague (requested → given or ignored, once) or given
 * by the employee's manager without a request. Seen with the initiative (Initiatives::mayView); never changed once given,
 * never deleted.
 */
#[Fillable(['tenant_id', 'initiative_id', 'reviewer_id', 'requested_by', 'status', 'feedback', 'requested_at', 'responded_at'])]
class InitiativeFeedback extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'initiative_feedback';

    protected $attributes = ['status' => 'requested'];

    protected static function booted(): void
    {
        static::updating(function (self $feedback): void {
            if ($feedback->getRawOriginal('status') !== 'requested') {
                throw new RuntimeException('This feedback was already given or ignored.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Initiative feedback is never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Initiative feedback #'.$this->id;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['feedback'];
    }

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
