<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Identity\Models\User;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Services\RatingNormalisation;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity GO-3 (matrix 27 §27.3.4 #4, HROne "Normalize performance rating"): a large normalise grid of the calibration
 * board (more rows changed than `peopleos.bulk.confirm_over`) runs as a WO-1 bulk run — one reason for the run, the rating
 * entered for each appraisal (and the rating the person saw), each appraisal through RatingNormalisation::normalise and so
 * Appraisals::calibrate: finalized, locked, withdrawn, one's own and out-of-scope appraisals are refused per row with the
 * reason. performance.calibrate (and bulk.run). Started only from the grid (it carries the ratings); not offered on
 * registers.
 */
final class NormaliseRatingsOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'appraisals.normalise';
    }

    public function label(): string
    {
        return 'Normalise ratings';
    }

    public function description(): string
    {
        return 'Each appraisal gets the normalised rating entered for it, with the reason, through the calibration rules: finalized, withdrawn, locked and your own appraisals are refused.';
    }

    public function model(): string
    {
        return Appraisal::class;
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
        return [Textarea::make('reason')->label('Why are the ratings normalised?')->required()->maxLength(500)];
    }

    /** @return array{reason: string, ratings: array<int, float>, expected: array<int, float|null>} */
    public function prepare(array $data, User $actor): array
    {
        $ratings = [];
        foreach ((array) ($data['ratings'] ?? []) as $id => $rating) {
            if (is_numeric($rating)) {
                $ratings[(int) $id] = (float) $rating;
            }
        }
        if ($ratings === []) {
            throw new RuntimeException('Enter at least one normalised rating.');
        }
        $expected = [];
        foreach ((array) ($data['expected'] ?? []) as $id => $value) {
            $expected[(int) $id] = is_numeric($value) ? (float) $value : null;
        }

        return ['reason' => RatingNormalisation::reason($data['reason'] ?? null), 'ratings' => $ratings, 'expected' => $expected];
    }

    public function with(): array
    {
        return ['employee.person', 'cycle', 'reviews'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var Appraisal $record */
        return app(RatingNormalisation::class)->refusal($record, $actor);
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Appraisal $record */
        $rating = RatingNormalisation::number(((array) ($input['ratings'] ?? []))[$record->getKey()] ?? null);
        if ($rating === null) {
            throw new RuntimeException('No rating was entered for this appraisal.');
        }
        $expected = RatingNormalisation::number(((array) ($input['expected'] ?? []))[$record->getKey()] ?? null);
        app(RatingNormalisation::class)->normalise($record, $rating, (string) ($input['reason'] ?? ''), $actor, $expected);

        return 'Normalised to '.rtrim(rtrim(number_format($rating, 2, '.', ''), '0'), '.');
    }
}
