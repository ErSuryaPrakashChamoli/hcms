<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Continuous feedback (§34): praise, constructive notes and feedback requests with replies. */
#[Fillable(['tenant_id', 'employee_id', 'author_id', 'type', 'visibility', 'message', 'goal_id', 'competency_id', 'requested_from_id', 'parent_id', 'status'])]
class FeedbackEntry extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return ucfirst($this->type).' feedback';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['message'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_id');
    }

    public function requestedFrom(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_from_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
