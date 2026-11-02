<?php

namespace App\Domain\Performance\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Imports\Concerns\ReadsPerformanceRows;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\RatingNormalisation;
use App\Domain\Performance\Support\RatingRules;

/**
 * Data import DI-35 ("Normalize performance ratings", HROne Import → Performance; matrix 27 §27.10 #5): normalised final
 * ratings of a performance cycle, one row per employee, each through RatingNormalisation::normalise — the GO-3 normalisation
 * grid's path (Appraisals::calibrate → Calibrations::adjust: an immutable calibration adjustment with the row's reason, the
 * appraisal's calibrated rating). The same rules as the grid: performance.calibrate (managers only while
 * performance.normalise.by_managers is on), the access scope, never one's own rating, not withdrawn, finalized or locked,
 * an active cycle at its calibration stage, a rating on the scale (a scale level while final ratings have no decimals).
 * The optional current rating guards against a rating changed by someone else since the file was made.
 */
final class RatingNormalisationImports extends RowImports
{
    use ReadsPerformanceRows;

    public const TYPE = 'rating_normalisation';

    public static function fields(): array
    {
        return ['employee_code' => ['Employee code', true], 'cycle_code' => ['Cycle code', true], 'rating' => ['Normalised rating', true],
            'reason' => ['Reason', true], 'expected' => ['Current rating', false]];
    }

    public static function intents(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Rating normalisation upload';
    }

    protected function module(): string
    {
        return 'performance';
    }

    protected function entityType(): string
    {
        return Appraisal::class;
    }

    protected function aliases(): array
    {
        return ['cycle' => 'cycle_code', 'final_rating' => 'rating', 'normalized_rating' => 'rating', 'remarks' => 'reason', 'system_rating' => 'expected', 'rating_before' => 'expected'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['actor' => $this->uploader($import)];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $cycle = $this->cycleOf($r['cycle_code'] ?? null, $context, $errors);
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
        $key = "{$employeeId}|{$cycle->id}";
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same employee and cycle are in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        $appraisal = $this->appraisalOf($cycle, $employeeId);
        if ($appraisal === null) {
            return ['action' => 'error', 'errors' => ["The employee has no appraisal in the cycle {$cycle->name}."], 'employee_id' => $employeeId];
        }
        $refusal = app(RatingNormalisation::class)->refusal($appraisal, $context['actor']) ?? $this->cycleRefusal($cycle, $rating);
        if ($refusal !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        $current = $appraisal->calibrated_rating ?? $appraisal->computed_rating;
        if ($expected !== null && ($current === null || abs((float) $current - $expected) > 0.001)) {
            return ['action' => 'error', 'errors' => ['The rating is now '.($current === null ? 'not worked out' : (float) $current).', not '.$expected.': it was changed since the file was made.'], 'employee_id' => $employeeId];
        }
        if ($appraisal->calibrated_rating !== null && abs((float) $appraisal->calibrated_rating - $rating) < 0.001) {
            return ['action' => 'skip', 'match' => ['id' => $appraisal->getKey(), 'reason' => 'The rating is already normalised to '.$rating.'.'], 'employee_id' => $employeeId];
        }

        return ['action' => 'update', 'match' => ['id' => $appraisal->getKey(), 'before' => $current === null ? null : (float) $current, 'after' => $rating], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $errors = [];
        $context = [];
        $cycle = $this->cycleOf($r['cycle_code'], $context, $errors);
        $appraisal = $cycle === null ? null : $this->appraisalOf($cycle, (int) $row->employee_id);
        if ($appraisal === null) {
            throw new \RuntimeException('The appraisal is no longer on file.');
        }
        $before = $appraisal->calibrated_rating ?? $appraisal->computed_rating;
        $done = app(RatingNormalisation::class)->normalise($appraisal, (float) $r['rating'], $r['reason'], $user, isset($r['expected']) ? (float) $r['expected'] : null);

        return 'Rating '.($before === null ? '—' : (float) $before).' → '.(float) $done->calibrated_rating;
    }

    /** The grid's own conditions: an active cycle and a rating on its scale (a level while final ratings take no decimals). */
    private function cycleRefusal(PerformanceCycle $cycle, float $rating): ?string
    {
        if ($cycle->status !== 'active') {
            return "Ratings are normalised while the cycle is active; {$cycle->name} is ".$cycle->status.'.';
        }
        $scale = $this->scaleOf($cycle);
        if ($rating < $scale->min() || $rating > $scale->max()) {
            return "Ratings must be between {$scale->min()} and {$scale->max()}.";
        }
        if (RatingRules::decimals() === 0 && ! array_key_exists((string) $rating, $scale->options()) && ! array_key_exists((string) (int) $rating, $scale->options())) {
            return 'Give one of the scale\'s ratings: '.implode(', ', array_keys($scale->options())).'.';
        }

        return null;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', 'The employee whose final rating is normalised.', 'EMP0001'],
                ['Cycle code', 'Yes', 'The active performance cycle (Performance → Cycles).', 'FY26'],
                ['Normalised rating', 'Yes', 'The final rating to use: a level of the cycle\'s rating scale (decimals only when Setup → Performance allows them).', '3'],
                ['Reason', 'Yes', 'Why the rating is normalised (at most 500 characters); kept on the calibration history.', 'Moderated by the review panel'],
                ['Current rating', 'No', 'The rating you saw (the normalised one, else the system\'s); a row whose rating changed since is refused.', '4'],
            ],
            'notes' => [
                'Each row goes through the normalisation grid\'s path: a calibration adjustment with the reason, and the appraisal\'s normalised rating. The same rating again is skipped.',
                'Withdrawn, finalized and locked appraisals are refused, as is your own rating; ratings are normalised while the cycle is active (and its calibration stage has opened).',
            ],
        ];
    }

    protected function examples(): array
    {
        $appraisals = Appraisal::query()->with(['employee', 'cycle'])->whereHas('cycle', fn ($q) => $q->where('status', 'active'))->whereNull('finalized_at')
            ->where('status', '!=', 'withdrawn')->orderBy('id')->limit(2)->get();
        $code = fn (int $i) => (string) ($appraisals->get($i)?->employee?->employee_code ?? Employee::query()->orderBy('id')->skip($i)->value('employee_code') ?? 'EMP000'.($i + 1));
        $cycle = fn (int $i) => (string) ($appraisals->get($i)?->cycle?->code ?? PerformanceCycle::query()->where('status', 'active')->orderByDesc('id')->value('code') ?? 'FY26');

        return [[$code(0), $cycle(0), '3', 'Moderated by the review panel', ''], [$code(1), $cycle(1), '4', 'Moderated by the review panel', '']];
    }
}
