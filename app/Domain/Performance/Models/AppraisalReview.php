<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One reviewer's input (self / manager / peer / upward / skip level / external). */
#[Fillable(['tenant_id', 'appraisal_id', 'type', 'reviewer_id', 'status', 'overall_rating', 'strengths', 'improvements', 'comments', 'is_anonymous', 'submitted_at'])]
class AppraisalReview extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['overall_rating' => 'decimal:2', 'is_anonymous' => 'boolean', 'submitted_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->type)).' review';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['strengths', 'improvements', 'comments'];
    }

    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(Appraisal::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(AppraisalRating::class);
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }
}
