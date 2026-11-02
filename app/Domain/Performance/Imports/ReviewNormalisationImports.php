<?php

namespace App\Domain\Performance\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Imports\Concerns\ReadsPerformanceRows;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Services\PerformancePolicyRules;
use App\Domain\Performance\Services\PeriodicReviews;
use App\Domain\Performance\Services\RatingNormalisation;

/**
 * Data import DI-35 ("Normalize review ratings", HROne Import → Performance; matrix 27 §27.10 #4): normalised ratings of a
 * cycle's periodic reviews (HP-8i), one row per employee and review, each through PeriodicReviews::normalise as the GO-3
 * "Normalise review ratings" grid does — performance.calibrate only, the access scope, never one's own rating, a submitted
 * review of an appraisal not withdrawn or final, the rating on the scale as far as the performance policy allows, a reason.
 * The appraisal is scored again, so the policy's roll-up reads the normalised rating.
 */
final class ReviewNormalisationImports extends RowImports
{
    use ReadsPerformanceRows;

    public const TYPE = 'review_normalisation';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'cycle_code' => ['Cycle code', true], 'review' => ['Review number', true],
            'rating' => ['Normalised rating', true], 'reason' => ['Reason', true], 'expected' => ['Current rating', false]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Review normalisation upload';
    }

    protected function module(): string
    {
        return 'performance';
    }

    protected function entityType(): string
    {
        return PerformancePeriodReview::class;
    }

    protected function aliases(): array
    {
        return ['cycle' => 'cycle_code', 'review_no' => 'review', 'sequence' => 'review', 'review_period' => 'review', 'normalized_rating' => 'rating', 'remarks' => 'reason',
            'rating_before' => 'expected'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => $this->uploader($import)];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $cycle = $this->cycleOf($r['cycle_code'] ?? null, $context, $errors);
        if (! ctype_digit((string) ($r['review'] ?? '')) || (int) $r['review'] < 1) {
            $errors[] = 'Review number is the periodic review\'s number in the cycle (1, 2, …).';
        }
        $rating = $this->ratingOf($r['rating'] ?? null, 'Normalised rating', $errors);
        $expected = isset($r['expected']) ? $this->ratingOf($r['expected'], 'Current rating', $errors) : null;
        try {
            RatingNormalisation::reason($r['reason'] ?? null);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
        if ($errors !== [] || $employeeId === null || $cycle === null || $context['actor'] === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $key = "{$employeeId}|{$cycle->id}|{$r['review']}";
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same review is in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        $review = $this->review($cycle, $employeeId, (int) $r['review']);
        if ($review === null) {
            return ['action' => 'error', 'errors' => ["The employee has no periodic review #{$r['review']} in the cycle {$cycle->name}."], 'employee_id' => $employeeId];
        }
        $refusal = app(PeriodicReviews::class)->normaliseRefusal($review, $context['actor']);
        if ($refusal === null && $cycle->status !== 'active') {
            $refusal = "Ratings are normalised while the cycle is active; {$cycle->name} is {$cycle->status}.";
        }
        if ($refusal === null) {
            $scale = $this->scaleOf($cycle);
            $max = app(PerformancePolicyRules::class)->maxRating($review->appraisal, $scale);
            $refusal = $rating < $scale->min() || $rating > $max ? "Ratings must be between {$scale->min()} and {$max}." : null;
        }
        if ($refusal !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        $current = $review->effectiveRating();
        if ($expected !== null && ($current === null || abs($current - $expected) > 0.001)) {
            return ['action' => 'error', 'errors' => ['The rating is now '.($current ?? '—').', not '.$expected.': it was changed since the file was made.'], 'employee_id' => $employeeId];
        }
        if ($review->normalised_rating !== null && abs((float) $review->normalised_rating - $rating) < 0.001) {
            return ['action' => 'skip', 'match' => ['id' => $review->getKey(), 'reason' => 'The review rating is already normalised to '.$rating.'.'], 'employee_id' => $employeeId];
        }

        return ['action' => 'update', 'match' => ['id' => $review->getKey(), 'before' => $current, 'after' => $rating], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $errors = [];
        $context = [];
        $cycle = $this->cycleOf($r['cycle_code'], $context, $errors);
        $review = $cycle === null ? null : $this->review($cycle, (int) $row->employee_id, (int) $r['review']);
        if ($review === null) {
            throw new \RuntimeException('The periodic review is no longer on file.');
        }
        $before = $review->effectiveRating();
        $done = app(PeriodicReviews::class)->normalise($review, (float) $r['rating'], $r['reason'], $user, isset($r['expected']) ? (float) $r['expected'] : null);

        return 'Review #'.$done->sequence.' rating '.($before ?? '—').' → '.(float) $done->normalised_rating;
    }

    private function review(PerformanceCycle $cycle, int $employeeId, int $sequence): ?PerformancePeriodReview
    {
        return PerformancePeriodReview::query()->with('appraisal')->where('performance_cycle_id', $cycle->id)->where('employee_id', $employeeId)->where('sequence', $sequence)->first();
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', 'The employee reviewed.', 'EMP0001'],
                ['Cycle code', 'Yes', 'The active performance cycle (Performance → Cycles).', 'FY26'],
                ['Review number', 'Yes', 'The periodic review\'s number in the cycle (1, 2, …).', '2'],
                ['Normalised rating', 'Yes', 'The rating to use for the review, on the cycle\'s scale.', '3'],
                ['Reason', 'Yes', 'Why it is normalised (at most 500 characters).', 'Quarter moderation'],
                ['Current rating', 'No', 'The rating you saw (the normalised one, else the reviewer\'s); a row whose rating changed since is refused.', '4'],
            ],
            'notes' => [
                'Only submitted periodic reviews are normalised; the reviewer\'s rating stays on file beside the normalised one, and the appraisal is worked out again.',
                'Needs the calibrate right; never your own rating. The same normalised rating again is skipped.',
            ],
        ];
    }

    protected function examples(): array
    {
        $submitted = PerformancePeriodReview::query()->with(['employee', 'appraisal.cycle'])->where('status', 'submitted')->orderBy('id')->limit(2)->get();
        $code = fn (int $i) => (string) ($submitted->get($i)?->employee?->employee_code ?? Employee::query()->orderBy('id')->skip($i)->value('employee_code') ?? 'EMP000'.($i + 1));
        $cycle = fn (int $i) => (string) ($submitted->get($i)?->appraisal?->cycle?->code ?? PerformanceCycle::query()->where('status', 'active')->orderByDesc('id')->value('code') ?? 'FY26');
        $sequence = fn (int $i) => (string) ($submitted->get($i)?->sequence ?? 1);

        return [[$code(0), $cycle(0), $sequence(0), '3', 'Quarter moderation', ''], [$code(1), $cycle(1), $sequence(1), '4', 'Quarter moderation', '']];
    }
}
