<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Domain\Platform\Services\SettingsRepository;
use RuntimeException;

/**
 * HROne parity GO-3 (matrix 27 §27.3.4 #3–4, HROne "Normalize performance rating"): many normalised (calibrated) ratings
 * of a cycle at once — one reason for the batch, each changed appraisal on its own through Appraisals::calibrate, which
 * keeps the calibration rules: the calibration stage must have opened, the rating lies on the cycle's scale, finalized and
 * locked appraisals are refused under the row lock, withdrawn ones are refused, a rating someone else changed meanwhile is
 * refused, and every change keeps its calibration history row and its audit event.
 *
 * This service decides who may normalise whom: performance HR (performance.calibrate) within their access scope, and —
 * only while the organisation switches on `performance.normalise.by_managers` (owner decision PER-2 / D-GO2, off by
 * default = today: normalising is HR's) — a manager (performance.team) for the people they manage
 * (PerformanceRelationships) whose ratings the performance policy lets them see. Nobody normalises their own rating.
 * A batch runs in one audit operation; the distribution check's warning (HP-8g, warn / block) is returned after it, as
 * after finalizing, and never stops a normalisation.
 */
final class RatingNormalisation
{
    public function __construct(
        private readonly Appraisals $appraisals,
        private readonly AuditRecorder $audit,
        private readonly AccessScopes $scopes,
        private readonly PerformanceRelationships $relationships,
        private readonly PerformanceDistribution $distribution,
        private readonly SettingsRepository $settings,
    ) {}

    /** Owner decision PER-2 / D-GO2: managers may normalise their own reports' ratings (off by default). */
    public function byManagers(): bool
    {
        return (bool) $this->settings->get('performance.normalise.by_managers', false);
    }

    /** Whether the person may normalise anyone at all (the screens offer the action only then). */
    public function mayNormalise(?User $user): bool
    {
        return $user !== null && ($user->hasPermission('performance.calibrate') || ($this->byManagers() && $user->hasPermission('performance.team')));
    }

    /** Whether the person normalises as a manager (no performance.calibrate; the setting on). */
    public function asManager(User $user): bool
    {
        return ! $user->hasPermission('performance.calibrate') && $this->byManagers() && $user->hasPermission('performance.team');
    }

    /** Why the person may not normalise this appraisal's rating; null = they may. */
    public function refusal(Appraisal $appraisal, User $actor): ?string
    {
        $me = $this->relationships->forUser($actor);
        $hr = $actor->hasPermission('performance.calibrate');
        $manager = ! $hr && $this->byManagers() && $actor->hasPermission('performance.team') && $this->relationships->manages($me, $appraisal->employee_id);
        if (! $hr && ! $manager) {
            return 'You cannot normalise this rating.';
        }
        if (! $this->scopes->allows($actor, $appraisal)) {
            return 'Outside your access scope.';
        }
        if ($me !== null && (int) $me->id === (int) $appraisal->employee_id) {
            return 'You cannot normalise your own rating.';
        }
        if ($appraisal->isWithdrawn()) {
            return Appraisals::WITHDRAWN;
        }
        if ($appraisal->isFinal()) {
            return 'The appraisal is finalized.';
        }
        if ($appraisal->isLocked()) {
            return 'The appraisal is locked: its cycle is closed.';
        }
        if ($manager && ! AppraisalVisibility::ratingsVisible($appraisal, $actor)) {
            return 'The performance policy keeps this rating from you.';
        }

        return null;
    }

    /** Normalise one appraisal's rating: who may (above), then Appraisals::calibrate with the reason. */
    public function normalise(Appraisal $appraisal, float $rating, string $reason, User $actor, ?float $expected = null): Appraisal
    {
        $reason = self::reason($reason);
        if (($refusal = $this->refusal($appraisal, $actor)) !== null) {
            throw new RuntimeException($refusal);
        }

        return $this->appraisals->calibrate($appraisal, $rating, $reason, $actor, $expected);
    }

    /**
     * A batch of the cycle: each change on its own — a refusal is reported with its reason and the rest goes on. One audit
     * operation for the batch ("Normalise ratings: <cycle>", the reason on it); each appraisal's own audit event and
     * calibration history row carry the reason too.
     *
     * @param  array<int|string, array{rating: float|int|string, expected?: float|int|string|null}>  $changes  appraisal id => change
     * @return array{done: list<int>, refused: array<int, string>, warning: ?string}
     */
    public function apply(PerformanceCycle $cycle, array $changes, string $reason, User $actor): array
    {
        $reason = self::reason($reason);
        if ($changes === []) {
            throw new RuntimeException('Enter at least one normalised rating.');
        }
        $done = [];
        $refused = [];
        $this->audit->operation('performance', "Normalise ratings: {$cycle->name}", function () use ($cycle, $changes, $reason, $actor, &$done, &$refused) {
            $appraisals = Appraisal::query()->withoutGlobalScope(AccessScope::class)->with(['employee', 'reviews'])
                ->where('performance_cycle_id', $cycle->id)->whereKey(array_map('intval', array_keys($changes)))->get()->keyBy('id');
            foreach ($changes as $id => $change) {
                $appraisal = $appraisals->get((int) $id);
                if ($appraisal === null) {
                    $refused[(int) $id] = 'Not an appraisal of this cycle.';

                    continue;
                }
                try {
                    $this->normalise($appraisal, self::number($change['rating'] ?? null) ?? throw new RuntimeException('Enter a rating.'), $reason, $actor, self::number($change['expected'] ?? null));
                    $done[] = (int) $id;
                } catch (RuntimeException $e) {
                    $refused[(int) $id] = $e->getMessage();
                }
            }

            return ['succeeded' => count($done), 'failed' => count($refused), 'ids' => $done];
        }, $reason, Appraisal::class);

        return ['done' => $done, 'refused' => $refused, 'warning' => $done === [] ? null : $this->distribution->warning($cycle)];
    }

    /**
     * The grid of a cycle for the chosen employees: one row per appraisal the person may normalise — employee, code, the
     * system-calculated rating, the normalised rating so far and the rating the person sees (for the "changed meanwhile"
     * check) — in alphabetical order (never by rating), and how many were left out, by reason. Withdrawn appraisals are
     * not part of a cycle's grid at all.
     *
     * @param  list<int>  $employeeIds
     * @return array{rows: list<array{appraisal_id: int, employee: ?string, code: ?string, system: ?float, normalised: ?float, expected: ?float}>, skipped: array<string, int>}
     */
    public function grid(PerformanceCycle $cycle, array $employeeIds, User $actor): array
    {
        $rows = [];
        $skipped = [];
        Appraisal::query()->withoutGlobalScope(AccessScope::class)->with(['employee.person', 'reviews'])
            ->where('performance_cycle_id', $cycle->id)->where('status', '!=', 'withdrawn')->whereIn('employee_id', $employeeIds ?: [0])->get()
            ->sortBy(fn (Appraisal $a) => mb_strtolower(($a->employee?->person?->full_name ?? '').' '.$a->employee?->employee_code))
            ->each(function (Appraisal $a) use ($actor, &$rows, &$skipped) {
                if (($refusal = $this->refusal($a, $actor)) !== null) {
                    $skipped[$refusal] = ($skipped[$refusal] ?? 0) + 1;

                    return;
                }
                $system = self::number($a->computed_rating);
                $normalised = self::number($a->calibrated_rating);
                $rows[] = ['appraisal_id' => (int) $a->id, 'employee' => $a->employee?->person?->full_name, 'code' => $a->employee?->employee_code,
                    'system' => $system, 'normalised' => $normalised, 'expected' => $normalised ?? $system];
            });

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** The batch reason: required, at most 500 characters (as a calibration note). */
    public static function reason(?string $reason): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new RuntimeException('Say why the ratings are normalised.');
        }
        if (mb_strlen($reason) > 500) {
            throw new RuntimeException('The reason is at most 500 characters.');
        }

        return $reason;
    }

    public static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
