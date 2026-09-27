<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A performance improvement plan (§34). Sensitive. */
#[Fillable(['tenant_id', 'employee_id', 'manager_id', 'appraisal_id', 'start_date', 'end_date', 'reason', 'objectives', 'status', 'outcome', 'closed_at', 'created_by'])]
class ImprovementPlan extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'objectives' => 'array', 'closed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Improvement plan '.$this->start_date?->toDateString();
    }

    public function auditSensitiveAttributes(): array
    {
        return ['reason', 'objectives', 'outcome'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(Appraisal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'active', 'extended'], true);
    }
}
