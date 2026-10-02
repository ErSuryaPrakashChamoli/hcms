<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\Survey;
use App\Domain\Engagement\Models\SurveyAnswer;
use App\Domain\Engagement\Models\SurveyParticipation;
use App\Domain\Engagement\Models\SurveyQuestion;
use App\Domain\Engagement\Models\SurveyResponse;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13: descriptive, privacy-preserving survey results — participation, distributions,
 * averages where meaningful, trend. No index, no individual scores, no inference.
 *
 * The rules (docs/architecture/engagement-communication.md §5):
 * - nothing below k respondents overall;
 * - groups only from the version's one pinned dimension (no ad-hoc filters);
 * - each group needs k respondents;
 * - complementary suppression: suppressed groups together hold 0 or at least k, so "total minus
 *   visible groups" never reveals a small group;
 * - the same rules again per question;
 * - free text overall only, above text_min_group, shuffled, without ids;
 * - anonymous / confidential results are released only after closing, so live differencing of
 *   successive views is impossible.
 *
 * Every number shown is either one of these aggregates or null (suppressed).
 */
final class EngagementAnalytics
{
    public function __construct(private readonly AccessScopes $scopes, private readonly GroupKeys $groups) {}

    public function k(): int
    {
        return $this->groups->minimum();
    }

    public function textK(): int
    {
        return max($this->k(), (int) config('peopleos.engagement.text_min_group', 10));
    }

    /** 'hr' | 'manager' | 'employee' | null — the widest view this user has on the version's results. */
    public function access(SurveyVersion $version, User $user): ?string
    {
        if ($user->hasPermission('engagement.analytics') && $version->visibleTo('hr') && $this->populationInScope($version, $user)) {
            return 'hr';
        }
        if ($user->hasPermission('engagement.team_results') && $version->visibleTo('managers') && $version->breakdown_dimension === 'manager' && $this->managerKey($version, $user) !== null) {
            return 'manager';
        }
        if ($user->hasPermission('engagement.participate') && $version->visibleTo('employees') && $this->isParticipant($version, $user)) {
            return 'employee';
        }

        return null;
    }

    public function released(SurveyVersion $version): bool
    {
        return $version->isIdentified() ? in_array($version->status, ['open', 'closed', 'archived'], true) : in_array($version->status, ['closed', 'archived'], true);
    }

    /** Participation counts (HR view only; group rows are counts, never content). */
    public function participation(SurveyVersion $version, User $user): array
    {
        if ($this->access($version, $user) !== 'hr') {
            throw new EngagementRuleViolation('You cannot see participation for this survey.');
        }
        $rows = AccessScope::withoutScoping(fn () => SurveyParticipation::query()->where('survey_version_id', $version->id)
            ->select('group_key', 'status', DB::raw('count(*) as n'))->groupBy('group_key', 'status')->get());
        $by = fn (?string $status = null) => (int) $rows->when($status, fn ($c) => $c->where('status', $status))->sum('n');
        $eligible = $by();
        $submitted = $by('submitted');

        return [
            'eligible' => $eligible, 'invited' => $eligible, 'opened' => $by('opened') + $submitted, 'submitted' => $submitted, 'expired' => $by('expired'),
            'response_rate' => $eligible > 0 ? round(100 * $submitted / $eligible, 1) : null,
            'groups' => $version->breakdown_dimension === null ? [] : $rows->groupBy(fn ($r) => (string) $r->group_key)->map(fn ($g, $key) => [
                'key' => $key === '' ? null : $key, 'label' => $this->groups->label($key === '' ? null : $key), 'eligible' => (int) $g->sum('n'),
                'submitted' => (int) $g->where('status', 'submitted')->sum('n'),
            ])->sortBy('label')->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function results(SurveyVersion $version, User $user): array
    {
        $view = $this->access($version, $user) ?? throw new EngagementRuleViolation('You cannot see the results of this survey.');

        return $this->compute($version, $view, $view === 'manager' ? $this->managerKey($version, $user) : null);
    }

    /** Integrations (API scope engagement.read): overall results only, under the same rules. */
    public function overall(SurveyVersion $version): array
    {
        return $this->compute($version, 'employee', null);
    }

    /** @return array<string, mixed> */
    private function compute(SurveyVersion $version, string $view, ?string $managerKey): array
    {
        $k = $this->k();
        $base = ['view' => $view, 'k' => $k, 'released' => $this->released($version), 'mode' => $version->anonymity_mode, 'respondents' => null, 'suppressed' => true, 'groups' => [], 'questions' => []];
        if (! $base['released']) {
            return $base + ['message' => 'Results of anonymous and confidential surveys are available once the survey closes.'];
        }

        $counts = $this->respondentsByGroup($version);
        $total = array_sum($counts);
        $base['respondents'] = $total >= $k ? $total : null;
        if ($total < $k) {
            return $base + ['message' => "Fewer than {$k} responses: results are suppressed to protect respondents."];
        }
        $base['suppressed'] = false;
        $groupCounts = array_filter($counts, fn ($n, $key) => $key !== '', ARRAY_FILTER_USE_BOTH);
        $visibleGroups = $version->breakdown_dimension ? $this->visible($groupCounts, $total, $k) : [];
        // HR: overall plus every group of the pinned dimension. Manager: their own team's group only
        // (never the overall population, which may lie outside their scope). Employee: overall only.
        $allowedGroups = match ($view) {
            'hr' => array_map('strval', array_keys($groupCounts)),
            'manager' => array_values(array_filter([$managerKey])),
            default => [],
        };
        foreach ($allowedGroups as $key) {
            $shown = in_array($key, $visibleGroups, true);
            $base['groups'][] = ['key' => $key, 'label' => $this->groups->label($key), 'respondents' => $shown ? $counts[$key] : null, 'suppressed' => ! $shown];
        }
        usort($base['groups'], fn ($a, $b) => strcmp($a['label'], $b['label']));
        if ($view === 'manager') {
            $base['respondents'] = $base['groups'][0]['respondents'] ?? null;
            $base['suppressed'] = $base['respondents'] === null;
        }

        $questions = SurveyQuestion::query()->where('survey_version_id', $version->id)->orderBy('position')->orderBy('id')->get();
        $stats = $this->questionStats($version);
        foreach ($questions as $question) {
            $cells = $stats[$question->id] ?? [];
            $answeredByGroup = array_map(fn ($c) => $c['answered'], $cells);
            $answeredTotal = array_sum($answeredByGroup);
            $entry = ['key' => $question->key, 'prompt' => $question->prompt, 'type' => $question->type, 'options' => $question->choiceOptions(), 'overall' => null, 'groups' => []];
            if ($answeredTotal >= $k) {
                // The same rules per question: only groups visible overall, each with k answers, and
                // complementary suppression against this question's own total.
                $visibleForQuestion = $this->visible(array_intersect_key($answeredByGroup, array_flip($visibleGroups)), $answeredTotal, $k);
                if ($view !== 'manager') {
                    $entry['overall'] = $this->summarise($question, array_values($cells), $answeredTotal);
                }
                foreach ($allowedGroups as $key) {
                    $entry['groups'][$key] = in_array($key, $visibleForQuestion, true) ? $this->summarise($question, [$cells[$key]], $cells[$key]['answered']) : null;
                }
            }
            $base['questions'][] = $entry;
        }

        return $base;
    }

    /**
     * Free-text answers to one question:
     * - overall only, with at least text_min_group answers;
     * - shuffled, without dates or groups;
     * - only for engagement.comments holders with the HR view.
     *
     * For CONFIDENTIAL surveys only, holders of engagement.confidential_identity also get an opaque
     * handle per comment, the one entry point of the reasoned, audited reveal
     * (ConfidentialIdentities). Anonymous comments never carry a handle.
     *
     * @return list<array{text: string, handle: ?string}>|null null = suppressed
     */
    public function comments(SurveyVersion $version, SurveyQuestion $question, User $user): ?array
    {
        if (! $user->hasPermission('engagement.comments') || $this->access($version, $user) !== 'hr') {
            throw new EngagementRuleViolation('You cannot read the comments of this survey.');
        }
        if ($question->type !== 'text' || (int) $question->survey_version_id !== (int) $version->id || ! $this->released($version)) {
            return null;
        }
        if (array_sum($this->respondentsByGroup($version)) < $this->textK()) {
            return null;
        }
        $handles = $version->anonymity_mode === 'confidential' && $user->hasPermission('engagement.confidential_identity');
        $rows = SurveyAnswer::query()->where('survey_version_id', $version->id)->where('question_id', $question->id)->whereNotNull('value_text')
            ->whereIn('response_id', SurveyResponse::query()->select('id')->where('survey_version_id', $version->id)->where('status', 'submitted'))
            ->limit(2000)->get(['response_id', 'value_text'])
            ->map(fn (SurveyAnswer $a) => ['text' => (string) $a->value_text, 'handle' => $handles ? (string) $a->response_id : null])
            ->filter(fn ($row) => $row['text'] !== '')->values()->all();
        if (count($rows) < $this->textK()) {
            return null;
        }
        shuffle($rows);

        return $rows;
    }

    /**
     * Overall results across the survey's closed versions (same question keys), each version
     * suppressed below k. Trend is never broken down by group.
     *
     * @return list<array<string, mixed>>
     */
    public function trend(Survey $survey, User $user): array
    {
        $out = [];
        $versions = SurveyVersion::query()->where('survey_id', $survey->id)->whereIn('status', ['closed', 'archived'])->whereNotNull('opened_at')->orderBy('version')->get();
        foreach ($versions as $version) {
            if ($this->access($version, $user) !== 'hr') {
                continue;
            }
            $total = array_sum($this->respondentsByGroup($version));
            $row = ['version' => $version->version, 'closed_at' => $version->closed_at?->toDateString(), 'eligible' => $version->eligible_count, 'respondents' => null, 'response_rate' => null, 'averages' => []];
            if ($total >= $this->k()) {
                $row['respondents'] = $total;
                $row['response_rate'] = $version->eligible_count ? round(100 * $total / $version->eligible_count, 1) : null;
                $questions = SurveyQuestion::query()->where('survey_version_id', $version->id)->whereIn('type', ['rating', 'likert', 'number'])->get()->keyBy('id');
                foreach ($this->questionStats($version) as $questionId => $groups) {
                    $answered = array_sum(array_column($groups, 'answered'));
                    if (isset($questions[$questionId]) && $answered >= $this->k()) {
                        $sum = array_sum(array_map(fn ($g) => $g['sum'], $groups));
                        $row['averages'][$questions[$questionId]->key] = round($sum / max(1, array_sum(array_map(fn ($g) => $g['numbers'], $groups))), 2);
                    }
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Complementary suppression. Start from groups with at least k; while the suppressed remainder
     * (total − shown) is between 1 and k − 1, also hide the smallest shown group.
     *
     * @param  array<string, int>  $counts  group key => count
     * @return list<string> keys that may be shown
     */
    public function visible(array $counts, int $total, int $k): array
    {
        $shown = array_filter($counts, fn (int $n) => $n >= $k);
        asort($shown);
        while ($shown !== []) {
            $remainder = $total - array_sum($shown);
            if ($remainder === 0 || $remainder >= $k) {
                break;
            }
            array_shift($shown);
        }

        return array_map('strval', array_keys($shown));
    }

    /** @return array<string, int> group key ('' = none) => respondents */
    private function respondentsByGroup(SurveyVersion $version): array
    {
        $counter = $version->isIdentified() ? 'count(distinct employee_id)' : 'count(*)';

        return SurveyResponse::query()->where('survey_version_id', $version->id)->where('status', 'submitted')
            ->groupBy('group_key')->select('group_key', DB::raw("{$counter} as n"))->get()
            ->mapWithKeys(fn ($r) => [(string) $r->group_key => (int) $r->n])->all();
    }

    /** @return array<int, array<string, array{answered: int, options: array<string, int>, sum: float, numbers: int}>> question id => group key => stats */
    private function questionStats(SurveyVersion $version): array
    {
        $rows = DB::table('survey_answers as a')->join('survey_responses as r', 'r.id', '=', 'a.response_id')
            ->where('a.survey_version_id', $version->id)->where('r.status', 'submitted')
            ->groupBy('a.question_id', 'r.group_key', 'a.value_option')
            ->select('a.question_id', 'r.group_key', 'a.value_option', DB::raw('count(*) as n'), DB::raw('count(distinct a.response_id) as responses'),
                DB::raw('sum(a.value_number) as total'), DB::raw('count(a.value_number) as numbers'))
            ->get();
        $answered = DB::table('survey_answers as a')->join('survey_responses as r', 'r.id', '=', 'a.response_id')
            ->where('a.survey_version_id', $version->id)->where('r.status', 'submitted')
            ->groupBy('a.question_id', 'r.group_key')->select('a.question_id', 'r.group_key', DB::raw('count(distinct a.response_id) as n'))->get();
        $out = [];
        foreach ($answered as $row) {
            $out[(int) $row->question_id][(string) $row->group_key] = ['answered' => (int) $row->n, 'options' => [], 'sum' => 0.0, 'numbers' => 0];
        }
        foreach ($rows as $row) {
            $cell = &$out[(int) $row->question_id][(string) $row->group_key];
            if ($row->value_option !== null) {
                $cell['options'][(string) $row->value_option] = (int) $row->n;
            }
            $cell['sum'] += (float) $row->total;
            $cell['numbers'] += (int) $row->numbers;
            unset($cell);
        }

        return $out;
    }

    /** @param  list<array{answered: int, options: array<string, int>, sum: float, numbers: int}>  $cells */
    private function summarise(SurveyQuestion $question, array $cells, int $answered): array
    {
        $distribution = [];
        foreach ($question->choiceOptions() as $value => $label) {
            $distribution[(string) $value] = array_sum(array_map(fn ($c) => $c['options'][(string) $value] ?? 0, $cells));
        }
        $numbers = array_sum(array_column($cells, 'numbers'));

        return [
            'answered' => $answered,
            'distribution' => $question->type === 'text' || $question->type === 'number' || $question->type === 'date' ? null : $distribution,
            'average' => $question->isNumeric() && $numbers > 0 ? round(array_sum(array_column($cells, 'sum')) / $numbers, 2) : null,
        ];
    }

    private function populationInScope(SurveyVersion $version, User $user): bool
    {
        if (! $this->scopes->isScoped($user)) {
            return true;
        }

        return AccessScope::withoutScoping(fn () => ! SurveyParticipation::query()->where('survey_version_id', $version->id)
            ->whereNotIn('employee_id', $this->scopes->employeeKeys($user))->exists());
    }

    private function managerKey(SurveyVersion $version, User $user): ?string
    {
        $employeeId = AccessScope::withoutScoping(fn () => Employee::query()->where('user_id', $user->id)->value('id'));
        if ($employeeId === null) {
            return null;
        }
        $key = 'manager:'.$employeeId;

        return AccessScope::withoutScoping(fn () => SurveyParticipation::query()->where('survey_version_id', $version->id)->where('group_key', $key)->exists()) ? $key : null;
    }

    private function isParticipant(SurveyVersion $version, User $user): bool
    {
        return AccessScope::withoutScoping(fn () => SurveyParticipation::query()->where('survey_version_id', $version->id)
            ->whereIn('employee_id', Employee::query()->select('id')->where('user_id', $user->id))->exists());
    }
}
