<?php

namespace App\Domain\Performance\Services;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Performance\Support\PerformancePeriods;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity HP-8h (SV-12; Performance → 360 Feedback → 360 policy, policy type `feedback_360`): reading the
 * answers of a 360 policy — from the version in force or from a round's snapshot — and checking them before they are
 * saved or published (validatePolicySettings). Blank answers: frequency yearly, window from the first day for 14 days,
 * no automatic launch; peers and subordinates anonymous, managers and clients named; nominated by the employee from
 * across the organisation with no approval; results shown to the employee only after the round closes (owner decision),
 * with at least three answers before an anonymous source is shown; no blend into the appraisal rating.
 */
final class Feedback360Rules
{
    /** @var list<string> */
    public const RELATIONS = ['manager', 'functional_manager', 'peer', 'subordinate', 'client'];

    /** @var list<string> sources the nominating role chooses (managers are asked by the system) */
    public const NOMINATED = ['peer', 'subordinate', 'client'];

    private const FLAGS = ['manager' => 'manager_feedback', 'functional_manager' => 'functional_manager_feedback', 'peer' => 'peer_feedback', 'subordinate' => 'subordinate_feedback', 'client' => 'client_feedback'];

    public function __construct(private readonly PolicyResolver $policies) {}

    public function policyFor(Employee $employee, CarbonInterface|string|null $on = null): ?PolicyVersion
    {
        return $this->policies->resolve('feedback_360', $employee, $on ?? today());
    }

    // --- Answers (from a policy version's settings or a round's snapshot) ----------------------------------------

    /** @param  array<string, mixed>  $settings */
    public static function enabled(array $settings, string $relation): bool
    {
        return isset(self::FLAGS[$relation]) && self::yes($settings[self::FLAGS[$relation]] ?? null);
    }

    /** @return list<string> the sources a policy collects feedback from */
    public static function relations(array $settings): array
    {
        return array_values(array_filter(self::RELATIONS, fn (string $r) => self::enabled($settings, $r)));
    }

    /** Peers and subordinates are anonymous unless the policy says no; managers and clients only when it says yes. */
    public static function anonymous(array $settings, string $relation): bool
    {
        return match ($relation) {
            'manager', 'functional_manager' => self::yes($settings['manager_anonymous'] ?? null),
            'peer' => ! self::no($settings['peer_anonymous'] ?? null),
            'subordinate' => ! self::no($settings['subordinate_anonymous'] ?? null),
            'client' => self::yes($settings['client_anonymous'] ?? null),
            default => true,
        };
    }

    public static function max(array $settings, string $relation): ?int
    {
        $value = $settings[$relation.'_max'] ?? null;

        return in_array($relation, self::NOMINATED, true) && is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return list<string> the position attributes a peer or subordinate shares with the assessed employee */
    public static function match(array $settings, string $relation): array
    {
        if (! in_array($relation, ['peer', 'subordinate'], true)) {
            return [];
        }
        $allowed = array_keys((array) config('peopleos.performance.feedback_360.match_dimensions', []));

        return array_values(array_intersect(array_map('strval', (array) ($settings[$relation.'_match'] ?? [])), $allowed));
    }

    public static function templateId(array $settings, string $relation): ?int
    {
        $key = in_array($relation, ['manager', 'functional_manager'], true) ? 'manager_template_id' : $relation.'_template_id';
        $value = $settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public static function frequency(array $settings): string
    {
        $frequency = (string) ($settings['frequency'] ?? '');

        return array_key_exists($frequency, (array) config('peopleos.performance.feedback_360.frequency_months')) ? $frequency : 'yearly';
    }

    public static function windowDays(array $settings): int
    {
        $days = $settings['window_days'] ?? null;

        return is_numeric($days) && (int) $days > 0 ? (int) $days : (int) config('peopleos.performance.feedback_360.default_window_days', 14);
    }

    public static function minResponses(array $settings): int
    {
        $min = $settings['min_responses'] ?? null;

        return is_numeric($min) && (int) $min >= 2 ? (int) $min : (int) config('peopleos.performance.feedback_360.default_min_responses', 3);
    }

    public static function nominator(array $settings): string
    {
        $nominator = (string) ($settings['nominator'] ?? '');

        return in_array($nominator, ['employee', 'manager', 'role'], true) ? $nominator : 'employee';
    }

    public static function scope(array $settings): string
    {
        return ($settings['nomination_scope'] ?? null) === 'direct_reportees' ? 'direct_reportees' : 'organisation';
    }

    public static function approval(array $settings): string
    {
        $approval = (string) ($settings['nomination_approval'] ?? '');

        return in_array($approval, ['manager', 'hr'], true) ? $approval : 'none';
    }

    /** Owner decision 2026-10-29 (open decision on peer visibility): blank = after the round closes. */
    public static function employeeSees(array $settings): bool
    {
        return ($settings['employee_sees'] ?? null) !== 'never';
    }

    public static function managerSees(array $settings): bool
    {
        return ! self::no($settings['manager_sees'] ?? null);
    }

    /** @return float|null the weight (%) of the 360 rating in the appraisal's computed rating; null = not blended (default) */
    public static function appraisalWeight(array $settings): ?float
    {
        $weight = $settings['appraisal_weight'] ?? null;

        return self::yes($settings['include_in_appraisal'] ?? null) && is_numeric($weight) && (float) $weight > 0 ? min(50.0, (float) $weight) : null;
    }

    /**
     * The period of the policy's frequency containing $day: months counted from the performance year's start month
     * (Setup → Performance), January when it is not set.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function periodContaining(array $settings, CarbonInterface $day): array
    {
        $months = (int) config('peopleos.performance.feedback_360.frequency_months.'.self::frequency($settings), 12);
        $startMonth = PerformancePeriods::yearStartMonth() ?: 1;
        $day = Carbon::parse($day->toDateString());
        $yearStart = Carbon::create($day->year, $startMonth, 1);
        if ($yearStart->gt($day)) {
            $yearStart->subYear();
        }
        $elapsed = ($day->year - $yearStart->year) * 12 + ($day->month - $yearStart->month);
        $start = $yearStart->copy()->addMonths(intdiv($elapsed, $months) * $months);

        return [$start, $start->copy()->addMonths($months)->subDay()];
    }

    /**
     * The response window of a period: from its "window start day" (1 = the first day, capped at the period's end) for
     * "window to complete 360 feedback" days.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function window(array $settings, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $day = $settings['window_start_day'] ?? null;
        $opens = Carbon::parse($periodStart->toDateString())->addDays(is_numeric($day) && (int) $day > 1 ? (int) $day - 1 : 0);
        if ($opens->gt($periodEnd)) {
            $opens = Carbon::parse($periodEnd->toDateString());
        }

        return [$opens, $opens->copy()->addDays(self::windowDays($settings) - 1)];
    }

    // --- Validator (policy wizard and publish) -------------------------------------------------------------------

    /** @param  array<string, mixed>  $settings */
    public function validatePolicySettings(array $settings): void
    {
        $relations = self::relations($settings);
        if ($relations === []) {
            throw new ConfigurationException('Collect feedback from at least one source (manager, functional manager, peers, subordinates or clients).');
        }
        $labels = (array) config('peopleos.performance.feedback_360.relations');
        foreach ($relations as $relation) {
            $id = self::templateId($settings, $relation);
            if ($id === null) {
                throw new ConfigurationException('Choose the feedback template for '.mb_strtolower($labels[$relation]).' feedback.');
            }
            if (! FeedbackTemplate::query()->active()->where('process', 'feedback_360')->whereKey($id)->exists()) {
                throw new ConfigurationException('The feedback template for '.mb_strtolower($labels[$relation]).' feedback must be an active 360 feedback template.');
            }
        }
        foreach (['peer', 'subordinate'] as $relation) {
            $chosen = array_filter((array) ($settings[$relation.'_match'] ?? []), 'filled');
            if (count($chosen) > 3) {
                throw new ConfigurationException('Choose at most three criteria for '.$labels[$relation].'s.');
            }
            if (count(self::match($settings, $relation)) !== count($chosen)) {
                throw new ConfigurationException('Unknown criterion for '.$labels[$relation].'s.');
            }
        }
        if (self::nominator($settings) === 'role' && blank($settings['nominator_role'] ?? null)) {
            throw new ConfigurationException('Choose the role that nominates 360 feedback stakeholders.');
        }
        $min = $settings['min_nominations'] ?? null;
        if (is_numeric($min) && (int) $min > 0) {
            $nominated = array_intersect($relations, self::NOMINATED);
            if ($nominated === []) {
                throw new ConfigurationException('A minimum number of nominations needs peer, subordinate or client feedback.');
            }
            $maxes = array_map(fn (string $r) => self::max($settings, $r), $nominated);
            if (! in_array(null, $maxes, true) && (int) $min > array_sum($maxes)) {
                throw new ConfigurationException('The minimum number of nominations is more than the maximums allow.');
            }
        }
        $months = (int) config('peopleos.performance.feedback_360.frequency_months.'.self::frequency($settings), 12);
        $day = $settings['window_start_day'] ?? null;
        if (is_numeric($day) && (int) $day > $months * 31) {
            throw new ConfigurationException('The window cannot start after the end of the cycle.');
        }
        if (self::yes($settings['include_in_appraisal'] ?? null) && ! is_numeric($settings['appraisal_weight'] ?? null)) {
            throw new ConfigurationException('Give the weight of the 360 rating in the appraisal rating.');
        }
    }

    private static function yes(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'true'], true);
    }

    private static function no(mixed $value): bool
    {
        return in_array($value, [false, 0, '0', 'no', 'false'], true);
    }
}
