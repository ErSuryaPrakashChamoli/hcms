<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'course_id', 'title', 'type', 'content', 'url', 'duration_minutes', 'sort_order'])]
class CourseModule extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
