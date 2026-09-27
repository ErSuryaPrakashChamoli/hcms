<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rule that enrols people (§37): one course or a path, to one employee or everyone matching
 * rule-engine conditions, due in N days, optionally recurring every N months (compliance refreshers).
 */
#[Fillable(['tenant_id', 'name', 'course_id', 'learning_path_id', 'employee_id', 'conditions', 'due_days', 'recur_months', 'is_mandatory', 'auto_enrol_new_joiners', 'status', 'created_by'])]
class LearningAssignment extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'due_days' => 'integer', 'recur_months' => 'integer', 'is_mandatory' => 'boolean', 'auto_enrol_new_joiners' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class, 'learning_path_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(LearningEnrolment::class);
    }
}
