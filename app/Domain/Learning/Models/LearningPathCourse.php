<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'learning_path_id', 'course_id', 'sort_order', 'is_required'])]
class LearningPathCourse extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_required' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return 'Path course #'.$this->course_id;
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class, 'learning_path_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
