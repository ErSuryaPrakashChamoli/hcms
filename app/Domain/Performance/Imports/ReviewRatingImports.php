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

/**
 * Data import DI-35 ("Review ratings", HROne Import → Performance; matrix 27 §27.10 #9): the ratings given in the periodic
 * reviews of a cycle (HP-8i: a review per quarter, half year … under the performance policy), one row per employee and
 * review, each submitted through PeriodicReviews::submit as the appraisal's "Periodic reviews" tab does — who may rate it
 * (performance HR or the reviewer, never the employee), an open review whose window has not closed, an appraisal not yet
 * final, a rating on the cycle's scale (above it only as far as the performance policy allows). The appraisal is scored
 * again. A submitted review is read-only: the same rating again is skipped, another one refused (normalise it instead).
 */
final class ReviewRatingImports extends RowImports
{
    use ReadsPerformanceRows;

    public const TYPE = 'review_ratings';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'cycle_code' => ['Cycle code', true], 'review' => ['Review number', true],
            'rating' => ['Rating', true], 'comment' => ['Comment', false]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Review rating upload';
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
        return ['cycle' => 'cycle_code', 'review_no' => 'review', 'sequence' => 'review', 'period' => 'review', 'review_period' => 'review', 'remarks' => 'comment'];
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
        $rating = $this->ratingOf($r['rating'] ?? null, 'Rating', $errors);
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
        $refusal = $this->refusal($review, $rating, $context['actor']);
        if ($refusal === 'submitted') {
            return abs((float) $review->rating - $rating) < 0.001
                ? ['action' => 'skip', 'match' => ['id' => $review->getKey(), 'reason' => 'This rating is already submitted.'], 'employee_id' => $employeeId]
                : ['action' => 'error', 'errors' => ['This periodic review was already submitted (rating '.(float) $review->rating.'); normalise it to change the rating.'], 'employee_id' => $employeeId];
        }
        if ($refusal !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }

        return ['action' => 'create', 'match' => ['id' => $review->getKey()], 'employee_id' => $employeeId];
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
        $submitted = app(PeriodicReviews::class)->submit($review, (float) $r['rating'], $r['comment'] ?? null, $user);

        return 'Review #'.$submitted->sequence.' rated '.(float) $submitted->rating;
    }

    private function review(PerformanceCycle $cycle, int $employeeId, int $sequence): ?PerformancePeriodReview
    {
        return PerformancePeriodReview::query()->with('appraisal.cycle')->where('performance_cycle_id', $cycle->id)->where('employee_id', $employeeId)->where('sequence', $sequence)->first();
    }

    /** Why the review cannot take this rating now ('submitted' when it already has one; null = it can). */
    private function refusal(PerformancePeriodReview $review, float $rating, User $actor): ?string
    {
        if (! app(PeriodicReviews::class)->mayRate($actor, $review)) {
            return 'This periodic review is not yours to rate.';
        }
        $appraisal = $review->appraisal;
        if ($appraisal->isWithdrawn()) {
            return 'The employee was withdrawn from the cycle.';
        }
        if ($appraisal->isFinal() || $appraisal->isLocked()) {
            return 'The appraisal is finalized.';
        }
        if ($review->status === 'submitted') {
            return 'submitted';
        }
        if ($review->status !== 'open') {
            return 'This periodic review is not open.';
        }
        if ($review->window_closes_on !== null && $review->window_closes_on->toDateString() < today()->toDateString()) {
            return 'The window of this periodic review has closed.';
        }
        $scale = $appraisal->cycle->ratingScale();
        $max = app(PerformancePolicyRules::class)->maxRating($appraisal, $scale);
        if ($rating < $scale->min() || $rating > $max) {
            return "Ratings must be between {$scale->min()} and {$max}.";
        }

        return null;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', 'The employee reviewed.', 'EMP0001'],
                ['Cycle code', 'Yes', 'The performance cycle (Performance → Cycles).', 'FY26'],
                ['Review number', 'Yes', 'The periodic review\'s number in the cycle (1 for the first quarter or half, 2 for the next …).', '2'],
                ['Rating', 'Yes', 'A rating on the cycle\'s rating scale.', '4'],
                ['Comment', 'No', 'The reviewer\'s comment (up to 2,000 characters).', 'Strong quarter'],
            ],
            'notes' => [
                'Each row submits one open periodic review, in your name, as the appraisal\'s Periodic reviews tab does; the appraisal\'s rating is worked out again.',
                'Only open reviews whose window has not closed take a rating. A submitted review is read-only: the same rating is skipped, another one is refused — use the Normalize review ratings upload.',
                'Needs performance HR rights (or being the review\'s reviewer); never your own review.',
            ],
        ];
    }

    protected function examples(): array
    {
        $open = PerformancePeriodReview::query()->with(['employee', 'appraisal.cycle'])->where('status', 'open')->whereDate('window_closes_on', '>=', today()->toDateString())
            ->orderBy('id')->limit(2)->get();
        $code = fn (int $i) => (string) ($open->get($i)?->employee?->employee_code ?? Employee::query()->orderBy('id')->skip($i)->value('employee_code') ?? 'EMP000'.($i + 1));
        $cycle = fn (int $i) => (string) ($open->get($i)?->appraisal?->cycle?->code ?? PerformanceCycle::query()->where('status', 'active')->orderByDesc('id')->value('code') ?? 'FY26');
        $sequence = fn (int $i) => (string) ($open->get($i)?->sequence ?? 1);

        return [[$code(0), $cycle(0), $sequence(0), '4', 'Strong quarter'], [$code(1), $cycle(1), $sequence(1), '3', '']];
    }
}
