<?php

namespace App\Domain\Performance\Services;

use App\Domain\Feedback\Support\FeedbackQuestions;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\FeedbackResponse;
use App\Domain\Performance\Models\FeedbackRound;
use App\Domain\Performance\Models\RatingScale;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * HROne setup parity HP-8h: the only read path for 360 answers — screens, the export and the appraisal blend all go
 * through it, so anonymity holds everywhere.
 *
 * - Who sees an employee's results: HR (performance.feedback_360 or performance.view) in their access scope, never about
 *   themselves; the employee's performance managers once the round has closed (unless the policy says no); the employee
 *   once the round has closed, unless the policy says "never" (owner decision 2026-10-29: after the round closes by
 *   default). A cancelled round's results are HR's only.
 * - An anonymous source is shown only aggregated — per question averages, option counts, comments in a fixed order with no
 *   names — and only once at least the policy's minimum number of people answered (3 by default); below it, only that it
 *   is not shown. A named source shows each answer with the reviewer's name.
 * - The 360 rating blended into an appraisal (when the round's policy asks for it) is the average score of the answers
 *   from peers, subordinates, functional managers and clients, used only when enough people answered.
 */
final class FeedbackRoundResults
{
    /** @var list<string> sources whose score may blend into the appraisal rating (the manager rates the appraisal itself) */
    public const BLENDED = ['peer', 'subordinate', 'functional_manager', 'client'];

    public function __construct(private readonly PerformanceRelationships $relationships, private readonly AccessScopes $scopes) {}

    public static function isHr(User $user): bool
    {
        return $user->hasPermission('performance.feedback_360') || $user->hasPermission('performance.view');
    }

    public function canSee(User $user, FeedbackParticipant $participant): bool
    {
        $participant->loadMissing(['round', 'employee']);
        $round = $participant->round;
        $settings = (array) $round->settings;
        if ((int) $participant->employee?->user_id === (int) $user->id) {
            return $round->status === 'closed' && Feedback360Rules::employeeSees($settings);
        }
        if (self::isHr($user) && $this->scopes->allows($user, $participant)) {
            return true;
        }
        if ($round->status !== 'closed' || ! Feedback360Rules::managerSees($settings)) {
            return false;
        }

        return $this->relationships->manages($this->relationships->forUser($user), $participant->employee_id);
    }

    /**
     * The status of each source (asked / answered / declined) — no answers. For anyone who may see the set.
     *
     * @return array<string, array{asked: int, given: int, declined: int}>
     */
    public function statusCounts(FeedbackParticipant $participant): array
    {
        $rows = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_participant_id', $participant->id)
            ->where('status', '!=', 'cancelled')->get(['relation', 'status']);
        $out = [];
        foreach ($rows->groupBy('relation') as $relation => $group) {
            $out[$relation] = ['asked' => $group->whereIn('status', ['requested', 'submitted', 'declined'])->count(), 'given' => $group->where('status', 'submitted')->count(), 'declined' => $group->where('status', 'declined')->count()];
        }

        return $out;
    }

    /**
     * The results of one assessed employee, as the viewer may see them (see the class comment).
     *
     * @return list<array{relation: string, label: string, anonymous: bool, asked: int, given: int, shown: bool, note: ?string, score: ?float, questions: list<array<string, mixed>>, named: list<array{reviewer: string, answers: list<array{title: string, answer: string}>}>}>
     */
    public function results(FeedbackParticipant $participant, User $viewer): array
    {
        if (! $this->canSee($viewer, $participant)) {
            throw new RuntimeException('You cannot see these 360 feedback results.');
        }

        return $this->compile($participant);
    }

    /**
     * HROne parity AN-27 (reports "360 feedback details"): per source of one assessed employee, the numbers the export
     * gives — asked, answered, declined and the average score, null for an anonymous source below the minimum — for HR
     * (performance.feedback_360 or performance.view) in their access scope, never about themselves; null for anyone else.
     * Never single answers, names, question averages or text.
     *
     * @return array<string, array{label: string, anonymous: bool, asked: int, given: int, declined: int, shown: bool, note: ?string, score: ?float}>|null keyed by source
     */
    public function aggregatesFor(FeedbackParticipant $participant, User $viewer): ?array
    {
        $participant->loadMissing(['round', 'employee']);
        if (! self::isHr($viewer) || (int) $participant->employee?->user_id === (int) $viewer->id || ! $this->scopes->allows($viewer, $participant)) {
            return null;
        }
        $counts = $this->statusCounts($participant);
        $out = [];
        foreach ($this->compile($participant, false) as $source) {
            $out[$source['relation']] = ['label' => $source['label'], 'anonymous' => $source['anonymous'], 'asked' => $source['asked'], 'given' => $source['given'],
                'declined' => $counts[$source['relation']]['declined'] ?? 0, 'shown' => $source['shown'], 'note' => $source['note'], 'score' => $source['score']];
        }

        return $out;
    }

    /**
     * @param  bool  $detail  false: the per-source numbers only (no question aggregates, no named answers)
     * @return list<array<string, mixed>>
     */
    private function compile(FeedbackParticipant $participant, bool $detail = true): array
    {
        $participant->loadMissing('round');
        $round = $participant->round;
        $settings = (array) $round->settings;
        $min = Feedback360Rules::minResponses($settings);
        $counts = $this->statusCounts($participant);
        $responses = FeedbackResponse::query()->withoutGlobalScope(AccessScope::class)->where('feedback_participant_id', $participant->id)->get();
        $labels = (array) config('peopleos.performance.feedback_360.relations');
        $out = [];
        foreach (Feedback360Rules::relations($settings) as $relation) {
            $template = $round->templateFor($relation);
            $given = $responses->where('relation', $relation)->values();
            $anonymous = Feedback360Rules::anonymous($settings, $relation);
            $shown = $given->isNotEmpty() && (! $anonymous || $given->count() >= $min);
            $note = match (true) {
                $given->isEmpty() => 'No feedback yet.',
                ! $shown => "Not shown: fewer than {$min} answers from an anonymous source.",
                default => null,
            };
            $questions = $template['questions'] ?? [];
            $out[] = [
                'relation' => $relation, 'label' => $labels[$relation] ?? $relation, 'anonymous' => $anonymous,
                'asked' => $counts[$relation]['asked'] ?? 0, 'given' => $given->count(), 'shown' => $shown, 'note' => $note,
                'score' => $shown ? $this->average($given) : null,
                'questions' => $shown && $detail ? array_map(fn (array $q) => $this->aggregate($q, $given), $questions) : [],
                'named' => $shown && $detail && ! $anonymous ? $this->named($given, $questions) : [],
            ];
        }

        return $out;
    }

    /**
     * Aggregated rows for an export of a round (HR in scope): per assessed employee and source, how many were asked and
     * answered and the average score — blank for an anonymous source below the minimum. Never single answers or text.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(FeedbackRound $round, User $viewer): array
    {
        if (! self::isHr($viewer)) {
            throw new RuntimeException('You cannot export 360 feedback results.');
        }
        $rows = [];
        $participants = FeedbackParticipant::query()->with('employee.person')->where('feedback_round_id', $round->id)->orderBy('id')->get();
        foreach ($participants as $participant) {
            if (! $this->scopes->allows($viewer, $participant) || (int) $participant->employee?->user_id === (int) $viewer->id) {
                continue;
            }
            $participant->setRelation('round', $round);
            foreach ($this->compile($participant) as $source) {
                $rows[] = [
                    'employee_code' => $participant->employee?->employee_code, 'employee' => $participant->employee?->person?->full_name,
                    'source' => $source['label'], 'anonymous' => $source['anonymous'] ? 'yes' : 'no', 'asked' => $source['asked'], 'answered' => $source['given'],
                    'average_score_pct' => $source['score'], 'note' => $source['note'],
                ];
            }
        }

        return $rows;
    }

    /**
     * HP-8h blend: the 360 rating of an appraisal on the cycle's scale, from the closed rounds linked to its cycle whose
     * policy asked to blend, with the weight to use; null when the policy did not ask or too few people answered.
     *
     * @return array{rating: float, weight: float}|null
     */
    public function appraisalBlend(Appraisal $appraisal, RatingScale $scale): ?array
    {
        $round = FeedbackRound::query()->where('performance_cycle_id', $appraisal->performance_cycle_id)->where('status', 'closed')
            ->whereHas('participants', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->where('employee_id', $appraisal->employee_id))
            ->orderByDesc('period_end')->orderByDesc('id')->first();
        if ($round === null || ($weight = Feedback360Rules::appraisalWeight((array) $round->settings)) === null) {
            return null;
        }
        $scores = FeedbackResponse::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $round->id)->where('employee_id', $appraisal->employee_id)
            ->whereIn('relation', self::BLENDED)->whereNotNull('score')->pluck('score')->map(fn ($s) => (float) $s);
        if ($scores->isEmpty() || $scores->count() < Feedback360Rules::minResponses((array) $round->settings)) {
            return null;
        }

        return ['rating' => round($scale->min() + ($scale->max() - $scale->min()) * $scores->avg() / 100, 2), 'weight' => $weight];
    }

    /**
     * Score of one answer set: the average share of the scale of its rating (1 … max) and agreement-scale answers, 0–100;
     * null when it has none.
     *
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     */
    public static function scoreOf(array $questions, array $answers): ?float
    {
        $shares = [];
        foreach ($questions as $q) {
            $value = $answers[$q['key']] ?? null;
            $max = match ($q['type']) {
                'rating' => (int) ($q['scale_max'] ?? 5), 'likert' => 5, default => null,
            };
            if ($max !== null && $max > 1 && is_numeric($value)) {
                $shares[] = ((float) $value - 1) / ($max - 1) * 100;
            }
        }

        return $shares === [] ? null : round(array_sum($shares) / count($shares), 2);
    }

    private function average(Collection $responses): ?float
    {
        $scores = $responses->pluck('score')->filter(fn ($s) => $s !== null)->map(fn ($s) => (float) $s);

        return $scores->isEmpty() ? null : round($scores->avg(), 2);
    }

    /** @return array<string, mixed> one question across the answers: average, option counts or the comments (sorted, unattributed) */
    private function aggregate(array $question, Collection $responses): array
    {
        $values = $responses->map(fn (FeedbackResponse $r) => $r->answers[$question['key']] ?? null)->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->values();
        $row = ['title' => $question['title'], 'type' => $question['type'], 'answers' => $values->count()];
        $labels = array_column($question['options'] ?? [], 'label', 'value');

        return $row + match ($question['type']) {
            'rating', 'likert', 'number' => ['average' => $values->isEmpty() ? null : round($values->avg(fn ($v) => (float) $v), 2), 'out_of' => $question['type'] === 'rating' ? (int) ($question['scale_max'] ?? 5) : ($question['type'] === 'likert' ? 5 : null)],
            'single_choice', 'multiple_choice', 'yes_no' => ['counts' => $values->flatten()->countBy()->mapWithKeys(fn ($n, $v) => [(string) ($labels[$v] ?? ($v === 'yes' ? 'Yes' : ($v === 'no' ? 'No' : $v))) => $n])->sortKeys()->all()],
            // Comments in alphabetical order: the order of the answers says nothing about who gave them.
            default => ['texts' => $values->map(fn ($v) => (string) $v)->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all()],
        };
    }

    /** @return list<array{reviewer: string, answers: list<array{title: string, answer: string}>}> */
    private function named(Collection $responses, array $questions): array
    {
        $nominations = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->with('reviewer.person')
            ->whereIn('id', $responses->pluck('feedback_nomination_id')->filter()->all() ?: [0])->get()->keyBy('id');

        return $responses->map(fn (FeedbackResponse $r) => [
            'reviewer' => $nominations->get($r->feedback_nomination_id)?->reviewerName() ?? '—',
            'answers' => array_map(fn (array $q) => ['title' => $q['title'], 'answer' => FeedbackQuestions::display($q, $r->answers[$q['key']] ?? null)], $questions),
        ])->sortBy('reviewer')->values()->all();
    }
}
