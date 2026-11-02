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

/**
 * HROne setup parity HP-8i (PF-7, PF-11): a periodic review inside an appraisal (e.g. Q1 of an annual cycle) when the
 * employee's performance policy runs a review cycle shorter than the appraisal cycle. scheduled → open (when its window
 * starts, or initiated by hand) → submitted (rated by the reporting manager), or missed when the window passes. It locks
 * with its appraisal; it is never deleted.
 *
 * HROne parity GO-3 ("Normalize review rating"): HR may normalise a submitted review's rating (PeriodicReviews::normalise).
 * The reviewer's rating stays as submitted; only the normalisation columns of a submitted review may change.
 */
#[Fillable(['tenant_id', 'appraisal_id', 'performance_cycle_id', 'employee_id', 'reviewer_id', 'sequence', 'period_start', 'period_end', 'window_opens_on', 'window_closes_on', 'manual', 'status', 'rating', 'comment', 'opened_by', 'opened_at', 'submitted_by', 'submitted_at', 'normalised_rating', 'normalisation_note', 'normalised_by', 'normalised_at'])]
class PerformancePeriodReview extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'scheduled', 'manual' => false];

    /** HROne parity GO-3: the only columns of a submitted review that may still change (its normalisation by HR). */
    public const NORMALISATION_COLUMNS = ['normalised_rating', 'normalisation_note', 'normalised_by', 'normalised_at'];

    protected static function booted(): void
    {
        static::updating(function (self $review): void {
            if ($review->getRawOriginal('status') === 'submitted'
                && array_diff(array_keys($review->getDirty()), [...self::NORMALISATION_COLUMNS, $review->getUpdatedAtColumn()]) !== []) {
                throw new \RuntimeException('A submitted periodic review is read-only.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Periodic reviews are never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'window_opens_on' => 'date', 'window_closes_on' => 'date',
            'manual' => 'boolean', 'rating' => 'decimal:2', 'sequence' => 'integer', 'opened_at' => 'datetime', 'submitted_at' => 'datetime',
            'normalised_rating' => 'decimal:2', 'normalised_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Periodic review '.$this->period_start?->toDateString().' – '.$this->period_end?->toDateString();
    }

    public function auditSensitiveAttributes(): array
    {
        // GO-3: the normalisation reason is confidential calibration talk, as the appraisal's calibration note.
        return ['comment', 'normalisation_note'];
    }

    /** HROne parity GO-3: the rating that stands — HR's normalised rating, else the reviewer's. */
    public function effectiveRating(): ?float
    {
        $value = ($this->getAttributes()['normalised_rating'] ?? null) ?? ($this->getAttributes()['rating'] ?? null);

        return $value === null ? null : (float) $value;
    }

    public function normaliser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'normalised_by');
    }

    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(Appraisal::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function label(): string
    {
        return $this->period_start->format('M Y').($this->period_start->format('Y-m') === $this->period_end->format('Y-m') ? '' : ' – '.$this->period_end->format('M Y'));
    }
}
