<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Designation;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the employee wants next (§36 Career Passport). */
#[Fillable(['tenant_id', 'employee_id', 'career_path_id', 'target_designation_id', 'aspirations', 'interests', 'open_to_relocation', 'open_to_role_change'])]
class CareerAspiration extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['interests' => 'array', 'open_to_relocation' => 'boolean', 'open_to_role_change' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Career aspiration';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function careerPath(): BelongsTo
    {
        return $this->belongsTo(CareerPath::class);
    }

    public function targetDesignation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'target_designation_id');
    }
}
