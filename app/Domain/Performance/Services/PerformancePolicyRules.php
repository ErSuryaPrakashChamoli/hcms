<?php

namespace App\Domain\Performance\Services;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Models\RatingScale;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity HP-8i (SV-11; Performance → Performance → Performance policy, policy type `performance`): the
 * answers of the performance policy that applies to an employee, and what PeopleOS does with them. Every blank answer —
 * and no policy at all — keeps today's appraisal behaviour exactly.
 *
 * Applied: the goal window (goals of a cycle set inside it; performance administrators not limited); periodic reviews
 * (review cycle shorter than the appraisal cycle: windows, automatic or manual opening — PeriodicReviews) and how they roll
 * up into the appraisal rating (average / last / custom weightage, with or without the year-end review); the review rating
 * method (weighted average or sum of all goals); ratings above the scale maximum when allowed; the goal / competency
 * weightage; copying the last level's inputs; who in the review chain sees ratings, comments, the final rating and the
 * final comments; reviewers' recommendations (training needs, promotion, increment, variable pay); eligibility by joining
 * date at launch; the feedback template on the performance review; the performance letter at publication. Other answers
 * are recorded on the policy (marked so in the form).
 *
 * Resolved once per employee per request (a scoped service), because visibility is asked for every row of a list.
 */
final class PerformancePolicyRules
{
    /** @var array<int, ?PolicyVersion> */
    private array $memo = [];

    /** Whether any performance policy is assigned at all (no policy: nothing is resolved per employee). */
    private ?bool $any = null;

    public const MONTHS = ['yearly' => 12, 'half_yearly' => 6, 'quarterly' => 3, 'monthly' => 1];

    /** The review chain, in order. */
    public const LEVELS = ['self', 'manager', 'approver'];

    public function __construct(private readonly PolicyResolver $policies) {}

    public function policyFor(?Employee $employee): ?PolicyVersion
    {
        if ($employee === null) {
            return null;
        }
        $this->any ??= PolicyAssignmentRule::query()->where('policy_type', 'performance')->where('status', 'active')->exists();
        if (! $this->any) {
            return null;
        }

        return array_key_exists($employee->id, $this->memo) ? $this->memo[$employee->id] : ($this->memo[$employee->id] = $this->policies->resolve('performance', $employee, today()));
    }

    public function forget(): void
    {
        $this->memo = [];
        $this->any = null;
    }

    public function value(?Employee $employee, string $key, mixed $default = null): mixed
    {
        return self::answer($this->policyFor($employee), $key, $default);
    }

    public static function answer(?PolicyVersion $policy, string $key, mixed $default = null): mixed
    {
        $value = $policy?->setting($key);

        return $value === null || $value === '' || $value === [] ? $default : $value;
    }

    private function forAppraisal(Appraisal $appraisal): ?PolicyVersion
    {
        return $this->policyFor($appraisal->loadMissing('employee')->employee);
    }

    // --- Eligibility (PF-11) -------------------------------------------------------------------------------------

    /** An employee who joined more than the policy's number of days after the cycle started is not eligible for it. */
    public function eligible(Employee $employee, PerformanceCycle $cycle): bool
    {
        $policy = $this->policyFor($employee);
        $days = self::answer($policy, 'joiner_cutoff_days');
        if (! self::yes(self::answer($policy, 'eligibility')) || ! is_numeric($days) || $employee->joining_date === null) {
            return true;
        }

        return Carbon::parse($employee->joining_date)->startOfDay()->lte($cycle->period_start->copy()->addDays((int) $days));
    }

    // --- Goal window (PF-6) ------------------------------------------------------------------------------------------

    /** @return array{0: Carbon, 1: Carbon}|null the employee's goal window in a cycle, or null (no window) */
    public function goalWindow(?Employee $employee, PerformanceCycle $cycle): ?array
    {
        $policy = $this->policyFor($employee);
        $days = self::answer($policy, 'goal_window_days');
        if (! is_numeric($days) || (int) $days < 1) {
            return null;
        }
        $offset = (int) self::answer($policy, 'goal_window_offset_days', 0);
        $opens = $cycle->period_start->copy()->startOfDay();
        $opens = self::answer($policy, 'goal_window_timing') === 'before' ? $opens->subDays($offset) : $opens->addDays($offset);

        return [$opens, $opens->copy()->addDays((int) $days - 1)];
    }

    // --- Periodic reviews (PF-7) ---------------------------------------------------------------------------------

    /**
     * The periodic reviews a cycle has for this policy: the cycle's period cut by the review cycle, each with its window
     * (from the period's end, before or after by the offset, for the window's days; 15 days by default). Empty when the
     * review cycle is not shorter than the cycle (one review per cycle, as before).
     *
     * @return list<array{sequence: int, period_start: string, period_end: string, window_opens_on: string, window_closes_on: string, manual: bool}>
     */
    public function periods(PerformanceCycle $cycle, ?PolicyVersion $policy): array
    {
        $months = self::MONTHS[(string) self::answer($policy, 'review_cycle', 'yearly')] ?? 12;
        $start = $cycle->period_start->copy()->startOfDay();
        $end = $cycle->period_end->copy()->startOfDay();
        $cycleMonths = (int) round($start->diffInMonths($end->copy()->addDay()));
        if ($months >= $cycleMonths) {
            return [];
        }
        $offset = (int) self::answer($policy, 'review_window_offset_days', 0);
        $days = (int) self::answer($policy, 'review_window_days', 15);
        $before = self::answer($policy, 'review_window_timing') === 'before';
        $periods = [];
        for ($i = 0, $from = $start->copy(); $from->lte($end); $i++, $from = $start->copy()->addMonths($months * $i)) {
            $to = $start->copy()->addMonths($months * ($i + 1))->subDay();
            $to = $to->gt($end) ? $end->copy() : $to;
            $opens = $before ? $to->copy()->subDays($offset) : $to->copy()->addDays($offset);
            $periods[] = ['sequence' => $i + 1, 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(),
                'window_opens_on' => $opens->toDateString(), 'window_closes_on' => $opens->copy()->addDays(max(1, $days) - 1)->toDateString(),
                'manual' => self::yes(self::answer($policy, 'review_manual_start'))];
        }

        return $periods;
    }

    /** Whether the user may open a periodic review by hand: performance administrators, or a holder of the policy's roles. */
    public function mayInitiate(User $user, ?Employee $employee): bool
    {
        if ($user->hasPermission('performance.manage')) {
            return true;
        }
        $roles = array_map('strval', (array) $this->value($employee, 'review_start_roles', []));

        return $roles !== [] && array_intersect($roles, $user->roleSlugs()) !== [];
    }

    /**
     * The appraisal rating from the periodic reviews, when the policy says how (average of all reviews, the last one, or a
     * custom weightage per review), with the year-end review's computed rating weighed in when the policy includes it.
     * Without a basis — or before any periodic review is rated — the computed rating stays as it is.
     */
    public function rollUp(Appraisal $appraisal, ?float $computed, RatingScale $scale): ?float
    {
        $policy = $this->forAppraisal($appraisal);
        $basis = self::answer($policy, 'appraisal_basis');
        if (! in_array($basis, ['average', 'last', 'custom'], true)) {
            return $computed;
        }
        $rated = PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->where('appraisal_id', $appraisal->id)
            ->where('status', 'submitted')->whereNotNull('rating')->orderBy('sequence')->get(['sequence', 'rating']);
        if ($rated->isEmpty()) {
            return $computed;
        }
        $periodic = match ($basis) {
            'last' => (float) $rated->last()->rating,
            'custom' => $this->customAverage($rated, (array) self::answer($policy, 'custom_weights', [])),
            default => (float) $rated->avg(fn ($r) => (float) $r->rating),
        };
        $weight = self::answer($policy, 'appraisal_weight');
        if ($computed !== null && self::yes(self::answer($policy, 'include_appraisal')) && is_numeric($weight)) {
            $w = max(0, min(100, (float) $weight)) / 100;
            $periodic = $periodic * (1 - $w) + $computed * $w;
        }

        return round(min($scale->max(), max($scale->min(), $periodic)), 2);
    }

    /** Custom weightage per review in order; reviews without a weightage count equally with what is left. */
    private function customAverage($rated, array $weights): float
    {
        $weights = array_values(array_map(fn ($w) => is_numeric($w) ? max(0.0, (float) $w) : 0.0, $weights));
        $total = 0.0;
        $sum = 0.0;
        foreach ($rated as $review) {
            $w = $weights[$review->sequence - 1] ?? 0.0;
            $sum += (float) $review->rating * $w;
            $total += $w;
        }

        return $total > 0 ? $sum / $total : (float) $rated->avg(fn ($r) => (float) $r->rating);
    }

    // --- Review rating (PF-7, PF-10) -----------------------------------------------------------------------------

    /** weighted (as before) or sum (Σ rating share × weightage ÷ 100, at most 100 %). */
    public function ratingMethod(Appraisal $appraisal): string
    {
        return self::answer($this->forAppraisal($appraisal), 'rating_method') === 'sum' ? 'sum' : 'weighted';
    }

    /** @return array{goals: float, competencies: float}|null the policy's goal / competency weightage, or null (the cycle's) */
    public function weights(Appraisal $appraisal): ?array
    {
        $policy = $this->forAppraisal($appraisal);
        $goals = self::answer($policy, 'goal_weight');
        $competencies = self::answer($policy, 'competency_weight');
        if (! is_numeric($goals) && ! is_numeric($competencies)) {
            return null;
        }

        return ['goals' => is_numeric($goals) ? (float) $goals : 100 - (float) $competencies, 'competencies' => is_numeric($competencies) ? (float) $competencies : 100 - (float) $goals];
    }

    /** The highest goal (or competency) rating a review may give: the scale's maximum, or the policy's when it allows more. */
    public function maxRating(Appraisal $appraisal, RatingScale $scale, string $what = 'goal'): float
    {
        $policy = $this->forAppraisal($appraisal);
        [$flag, $key] = $what === 'competency' ? ['competency_above_max', 'max_competency_rating'] : ['allow_above_max', 'max_goal_rating'];
        $max = self::answer($policy, $key);

        return self::yes(self::answer($policy, $flag)) && is_numeric($max) && (float) $max > $scale->max() ? (float) $max : $scale->max();
    }

    /** Copy the last level's inputs: the policy's answer, or null (the organisation setting decides). */
    public function copiesInputs(Appraisal $appraisal, string $what = 'goal'): ?bool
    {
        $answer = self::answer($this->forAppraisal($appraisal), $what === 'competency' ? 'copy_competency_inputs' : 'copy_last_level');

        return $answer === null ? null : self::yes($answer);
    }

    // --- Visibility (PF-8) ----------------------------------------------------------------------------------------

    /** ratings (ratings and comments of the review levels), final_rating or final_comments: the policy's answer, 'all' by default. */
    public function visibility(Appraisal $appraisal, string $what): string
    {
        $key = match ($what) {
            'final_rating' => 'final_rating_visible_to', 'final_comments' => 'final_comments_visible_to', default => 'ratings_visible_to',
        };
        $value = (string) self::answer($this->forAppraisal($appraisal), $key, 'all');

        return in_array($value, ['all', 'preceding_level', 'last_level', 'reviewer', 'all_except_employee', 'none'], true) ? $value : 'all';
    }

    // --- Recommendations (PF-9) -------------------------------------------------------------------------------------

    /** @return array{training: bool, promotion: bool, increment: bool, increment_type: string, variable: bool} */
    public function recommendationOptions(Appraisal $appraisal): array
    {
        $policy = $this->forAppraisal($appraisal);

        return [
            'training' => self::yes(self::answer($policy, 'recommend_training')),
            'promotion' => self::yes(self::answer($policy, 'recommend_promotion')),
            'increment' => self::yes(self::answer($policy, 'recommend_increment')),
            'increment_type' => self::answer($policy, 'increment_type') === 'amount' ? 'amount' : 'percentage',
            'variable' => self::yes(self::answer($policy, 'recommend_variable')),
        ];
    }

    public function recommends(Appraisal $appraisal): bool
    {
        $options = $this->recommendationOptions($appraisal);

        return $options['training'] || $options['promotion'] || $options['increment'] || $options['variable'];
    }

    /**
     * The recommendations a manager or approver review carries, as the policy enables them: training needs (competencies
     * and a note), promotion, increment (amount or percentage) and variable pay. Null when the policy enables none or the
     * review says nothing.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public function cleanRecommendations(Appraisal $appraisal, array $input, string $reviewType): ?array
    {
        if (! in_array($reviewType, ['manager', 'approver'], true) || ! $this->recommends($appraisal)) {
            return null;
        }
        $options = $this->recommendationOptions($appraisal);
        $out = [];
        if ($options['training']) {
            $competencies = array_values(array_unique(array_map('intval', array_filter((array) ($input['training_competencies'] ?? []), 'is_numeric'))));
            $note = trim((string) ($input['training_note'] ?? ''));
            if ($competencies !== [] || $note !== '') {
                $out['training'] = ['competency_ids' => $competencies, 'note' => mb_strimwidth($note, 0, 500, '…')];
            }
        }
        if ($options['promotion'] && array_key_exists('promotion', $input) && $input['promotion'] !== null) {
            $out['promotion'] = (bool) $input['promotion'];
        }
        foreach (['increment' => 'increment', 'variable' => 'variable'] as $key => $option) {
            $value = $input[$key] ?? null;
            if (! $options[$option] || $value === null || $value === '') {
                continue;
            }
            if (! is_numeric($value) || (float) $value < 0 || ($options['increment_type'] === 'percentage' && (float) $value > 100)) {
                throw new \RuntimeException('Recommend an '.$key.' of 0 or more'.($options['increment_type'] === 'percentage' ? ' (at most 100 %)' : '').'.');
            }
            $out[$key] = ['type' => $options['increment_type'], 'value' => round((float) $value, 2)];
        }

        return $out === [] ? null : $out + ['by' => $reviewType, 'at' => now()->toDateTimeString()];
    }

    // --- Feedback template and performance letter (PF-6, PF-11) ----------------------------------------------------

    public function reviewFeedbackTemplate(?Employee $employee): ?int
    {
        $id = $this->value($employee, 'review_feedback_template_id');

        return self::yes($this->value($employee, 'review_feedback')) && is_numeric($id) ? (int) $id : null;
    }

    public function letterTemplate(?Employee $employee): ?int
    {
        $id = $this->value($employee, 'letter_template_id');

        return self::yes($this->value($employee, 'performance_letter')) && is_numeric($id) ? (int) $id : null;
    }

    // --- Validator ---------------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $settings */
    public function validatePolicySettings(array $settings): void
    {
        $goal = self::MONTHS[(string) ($settings['goal_cycle'] ?? '')] ?? null;
        $review = self::MONTHS[(string) ($settings['review_cycle'] ?? '')] ?? null;
        if ($goal !== null && $review !== null && $review > $goal) {
            throw new ConfigurationException('The review cycle cannot be longer than the goal cycle (a half-yearly goal cycle has half-yearly, quarterly or monthly reviews).');
        }
        $goalWeight = $settings['goal_weight'] ?? null;
        $competencyWeight = $settings['competency_weight'] ?? null;
        if (is_numeric($goalWeight) && is_numeric($competencyWeight) && abs((float) $goalWeight + (float) $competencyWeight - 100) > 0.001) {
            throw new ConfigurationException('Competency weightage and goal weightage add up to 100.');
        }
        foreach (['allow_above_max' => 'max_goal_rating', 'competency_above_max' => 'max_competency_rating'] as $flag => $key) {
            if (self::yes($settings[$flag] ?? null) && ! is_numeric($settings[$key] ?? null)) {
                throw new ConfigurationException('Give the highest rating allowed above the scale maximum.');
            }
        }
        if (($settings['appraisal_basis'] ?? null) === 'custom') {
            $weights = array_values(array_filter((array) ($settings['custom_weights'] ?? []), 'filled'));
            if ($weights === [] || array_filter($weights, fn ($w) => ! is_numeric($w) || (float) $w < 0) !== []) {
                throw new ConfigurationException('Give a weightage (a number) for each periodic review.');
            }
            if (abs(array_sum(array_map('floatval', $weights)) - 100) > 0.001) {
                throw new ConfigurationException('The weightages of the periodic reviews add up to 100.');
            }
            if ($review === null || $review >= 12) {
                throw new ConfigurationException('A custom weightage per review needs a review cycle shorter than a year.');
            }
        }
        if (self::yes($settings['include_appraisal'] ?? null) && ! is_numeric($settings['appraisal_weight'] ?? null)) {
            throw new ConfigurationException('Give the appraisal weightage.');
        }
        if (self::yes($settings['eligibility'] ?? null) && ! is_numeric($settings['joiner_cutoff_days'] ?? null)) {
            throw new ConfigurationException('Give the number of days after the start of the cycle for the eligibility criteria.');
        }
        foreach (['review_feedback' => 'review_feedback_template_id', 'performance_letter' => 'letter_template_id'] as $flag => $key) {
            if (self::yes($settings[$flag] ?? null) && ! is_numeric($settings[$key] ?? null)) {
                throw new ConfigurationException($flag === 'performance_letter' ? 'Choose the performance letter template.' : 'Choose the feedback template for the performance review.');
            }
        }
    }

    public static function yes(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'true'], true);
    }
}
