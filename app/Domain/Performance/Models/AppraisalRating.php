<?php

namespace App\Domain\Performance\Models;

use App\Domain\Identity\Scopes\AccessScope;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A rating for one goal or competency within a review. */
#[Fillable(['tenant_id', 'appraisal_review_id', 'subject_type', 'subject_id', 'rating', 'comment'])]
class AppraisalRating extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        // Phase 7: frozen once the appraisal is locked (cycle closed).
        $guard = function (self $m) {
            if (Appraisal::query()->withoutGlobalScope(AccessScope::class)->whereKey(AppraisalReview::query()->whereKey($m->appraisal_review_id)->value('appraisal_id'))->whereNotNull('locked_at')->exists()) {
                throw new \RuntimeException('This appraisal is locked: its cycle is closed.');
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['rating' => 'decimal:2'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(AppraisalReview::class, 'appraisal_review_id');
    }
}
