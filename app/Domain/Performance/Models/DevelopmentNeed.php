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
 * Phase 7 boundary for a future Learning module: a development need identified in performance
 * (review, PIP, check-in, manual). Performance records it; Learning may read it through
 * DevelopmentNeedsReader. No courses, enrolments or learning logic live here.
 */
#[Fillable(['tenant_id', 'employee_id', 'source_type', 'source_id', 'competency_id', 'title', 'description', 'priority', 'status', 'created_by'])]
class DevelopmentNeed extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const SOURCES = ['appraisal', 'improvement_plan', 'check_in', 'one_on_one', 'manual'];

    protected $attributes = ['status' => 'open', 'priority' => 'medium'];

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Development need: '.$this->title;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
