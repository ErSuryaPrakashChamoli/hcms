<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Support\AppraisalVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-27 (HROne reports "Performance review rating by approver", "Performance feedback details"): one row
 * per review of an appraisal — self, manager, approver, peer … — with the reviewer, status, the overall rating and the
 * feedback written in it. For performance HR (performance.view or performance.calibrate, as Performance → Review
 * requests) in their access scope. The rating and comments follow AppraisalVisibility::reviewVisible for the reader (the
 * performance policy's per-level visibility; HR sees every level), so a report never shows what the Reviews tab would
 * hide; a run without a reader shows none. The comment text needs performance.view; the reviewer of a review marked
 * anonymous is never named.
 */
class AppraisalReviewsDataset extends Dataset
{
    public function key(): string
    {
        return 'appraisal_reviews';
    }

    public function label(): string
    {
        return 'Appraisal reviews (rating and feedback by reviewer)';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.view', 'performance.calibrate'];
    }

    public function fields(): array
    {
        $visible = fn (AppraisalReview $r) => self::visible($r);
        $text = fn (string $label, string $column) => ['label' => $label, 'type' => 'string', 'permission' => 'performance.view',
            'value' => fn (AppraisalReview $r) => $visible($r) ? $r->getAttribute($column) : null];

        return [
            'cycle' => ['label' => 'Cycle', 'type' => 'string', 'value' => fn (AppraisalReview $r) => $r->appraisal?->cycle?->name],
            ...$this->employeeFields(fn (AppraisalReview $r) => $r->appraisal?->employee),
            'level' => ['label' => 'Review level', 'type' => 'string', 'value' => fn (AppraisalReview $r) => config("peopleos.performance.review_types.{$r->type}", $r->type)],
            'level_code' => ['label' => 'Review level (code)', 'type' => 'string', 'value' => fn (AppraisalReview $r) => $r->type],
            'reviewer' => ['label' => 'Reviewer', 'type' => 'string', 'value' => fn (AppraisalReview $r) => self::reviewerName($r)],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn (AppraisalReview $r) => $r->status],
            'submitted_on' => ['label' => 'Submitted on', 'type' => 'date', 'value' => fn (AppraisalReview $r) => $r->submitted_at],
            'overall_rating' => ['label' => 'Overall rating', 'type' => 'number', 'value' => fn (AppraisalReview $r) => $visible($r) && $r->overall_rating !== null ? (float) $r->overall_rating : null],
            'items_rated' => ['label' => 'Goals / KPIs / competencies rated', 'type' => 'number', 'value' => fn (AppraisalReview $r) => $visible($r) ? (int) $r->getAttribute('ratings_count') : null],
            'has_comments' => ['label' => 'Feedback written', 'type' => 'boolean', 'value' => fn (AppraisalReview $r) => $visible($r) ? collect(['strengths', 'improvements', 'comments'])->contains(fn ($c) => trim((string) $r->getAttribute($c)) !== '') : null],
            'strengths' => $text('Strengths', 'strengths'),
            'improvements' => $text('Areas to improve', 'improvements'),
            'comments' => $text('Comments', 'comments'),
            'appraisal_status' => ['label' => 'Appraisal status', 'type' => 'string', 'value' => fn (AppraisalReview $r) => $r->appraisal?->status],
            'count' => ['label' => 'Reviews (1 per row)', 'type' => 'number', 'value' => fn (AppraisalReview $r) => 1],
        ];
    }

    public function query(): Builder
    {
        // Reviews are not access-scoped themselves; requiring a visible appraisal applies the reader's scope.
        return AppraisalReview::query()->whereHas('appraisal')
            ->with(['appraisal.cycle', 'appraisal.reviews', ...self::employeeWith('appraisal.employee'), 'reviewer.person'])
            ->withCount('ratings')
            ->orderByDesc('appraisal_id')->orderBy('id');
    }

    /** Whether the reader may see this review's ratings and comments (AppraisalVisibility); never without a reader. */
    public static function visible(AppraisalReview $review): bool
    {
        $viewer = auth()->user();

        return $viewer instanceof User && $review->appraisal !== null && AppraisalVisibility::reviewVisible($review->appraisal, $review, $viewer);
    }

    /** The reviewer as a report may name them: never the reviewer of a review marked anonymous. */
    public static function reviewerName(AppraisalReview $review): ?string
    {
        return $review->is_anonymous ? 'Anonymous' : $review->reviewer?->person?->full_name;
    }
}
