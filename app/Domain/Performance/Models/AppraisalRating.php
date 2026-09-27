<?php

namespace App\Domain\Performance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A rating for one goal or competency within a review. */
#[Fillable(['tenant_id', 'appraisal_review_id', 'subject_type', 'subject_id', 'rating', 'comment'])]
class AppraisalRating extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['rating' => 'decimal:2'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(AppraisalReview::class, 'appraisal_review_id');
    }
}
