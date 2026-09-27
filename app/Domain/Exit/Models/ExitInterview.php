<?php

namespace App\Domain\Exit\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Exit interview (§61). `source` separates the employee's own answers from HR-inferred ones. */
#[Fillable(['tenant_id', 'exit_case_id', 'employee_id', 'conducted_by', 'conducted_at', 'reason_for_leaving', 'ratings', 'would_recommend', 'would_rejoin', 'liked_most', 'suggestions', 'source', 'status'])]
class ExitInterview extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'draft', 'source' => 'employee'];

    protected function casts(): array
    {
        return ['conducted_at' => 'datetime', 'ratings' => 'array', 'would_recommend' => 'boolean', 'would_rejoin' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'exit';
    }

    public function auditLabel(): string
    {
        return 'Exit interview';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['ratings', 'liked_most', 'suggestions'];
    }

    public function exitCase(): BelongsTo
    {
        return $this->belongsTo(ExitCase::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }
}
