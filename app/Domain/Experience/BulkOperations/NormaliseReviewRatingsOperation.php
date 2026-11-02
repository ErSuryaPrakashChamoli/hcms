<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Services\PeriodicReviews;
use App\Domain\Performance\Services\RatingNormalisation;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity GO-3 (matrix 27 §27.3.4 #2, HROne "Normalize review rating"): a large grid of one review period's periodic
 * review ratings (HP-8i) runs as a WO-1 bulk run — one reason, the rating entered for each review, each through
 * PeriodicReviews::normalise (submitted reviews only; finalized, locked or withdrawn appraisals, one's own and
 * out-of-scope reviews refused per row). performance.calibrate (and bulk.run). Started only from the grid.
 */
final class NormaliseReviewRatingsOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'period_reviews.normalise';
    }

    public function label(): string
    {
        return 'Normalise review ratings';
    }

    public function description(): string
    {
        return 'Each submitted periodic review gets the normalised rating entered for it, with the reason; the reviewer\'s own rating is kept.';
    }

    public function model(): string
    {
        return PerformancePeriodReview::class;
    }

    public function permission(): string
    {
        return 'performance.calibrate';
    }

    public function icon(): string
    {
        return 'heroicon-m-adjustments-vertical';
    }

    public function color(): string
    {
        return 'warning';
    }

    public function schema(): array
    {
        return [Textarea::make('reason')->label('Why are the review ratings normalised?')->required()->maxLength(500)];
    }

    /** @return array{reason: string, ratings: array<int, float>, expected: array<int, float|null>} */
    public function prepare(array $data, User $actor): array
    {
        return (new NormaliseRatingsOperation)->prepare($data, $actor);
    }

    public function with(): array
    {
        return ['employee.person', 'appraisal'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var PerformancePeriodReview $record */
        return app(PeriodicReviews::class)->normaliseRefusal($record, $actor);
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var PerformancePeriodReview $record */
        $rating = RatingNormalisation::number(((array) ($input['ratings'] ?? []))[$record->getKey()] ?? null);
        if ($rating === null) {
            throw new RuntimeException('No rating was entered for this review.');
        }
        $expected = RatingNormalisation::number(((array) ($input['expected'] ?? []))[$record->getKey()] ?? null);
        app(PeriodicReviews::class)->normalise($record, $rating, (string) ($input['reason'] ?? ''), $actor, $expected);

        return 'Normalised to '.rtrim(rtrim(number_format($rating, 2, '.', ''), '0'), '.');
    }
}
