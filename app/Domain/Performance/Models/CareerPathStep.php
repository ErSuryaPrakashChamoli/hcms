<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\Designation;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One rung: designation + required skills [{skill_id, proficiency}] + required competencies [{competency_id, rating}]. */
#[Fillable(['tenant_id', 'career_path_id', 'designation_id', 'sort_order', 'required_skills', 'required_competencies', 'typical_years'])]
class CareerPathStep extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'required_skills' => 'array', 'required_competencies' => 'array', 'typical_years' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Career step #'.$this->sort_order;
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(CareerPath::class, 'career_path_id');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
}
