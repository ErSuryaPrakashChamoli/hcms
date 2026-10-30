<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\MoodCheckIn;
use App\Domain\Engagement\Models\MoodReason;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity HP-9f: the happiness index — mood bot results under the survey privacy rules
 * (docs/architecture/engagement-communication.md §5):
 *
 * - only periods that have ended (live differencing of an open period is impossible);
 * - nothing for a period with fewer than k check-ins (engagement.analytics_min_group);
 * - groups only from the department keys fixed at check-in, each with k check-ins, with complementary suppression;
 * - comments only overall, for engagement.comments holders, above text_min_group, shuffled, without dates or groups
 *   (a handle only on confidential comments, for engagement.confidential_identity holders: the audited reveal);
 * - identified check-ins (when the organisation chose them) only for engagement.responses, within the viewer's scope.
 *
 * The HR view needs engagement.analytics and an unrestricted access scope (the index covers the whole organisation).
 */
final class MoodAnalytics
{
    public function __construct(
        private readonly EngagementAnalytics $analytics,
        private readonly GroupKeys $groups,
        private readonly MoodCheckIns $moods,
        private readonly AccessScopes $scopes,
    ) {}

    public function k(): int
    {
        return $this->analytics->k();
    }

    public function canView(User $user): bool
    {
        return $user->hasPermission('engagement.analytics') && ! $this->scopes->isScoped($user);
    }

    /**
     * The happiness index of the latest $limit periods that have ended, newest first.
     *
     * @return array<string, mixed>
     */
    public function results(User $viewer, int $limit = 6): array
    {
        if (! $this->canView($viewer)) {
            throw new EngagementRuleViolation('You cannot see the happiness index.');
        }
        $k = $this->k();
        $stats = $this->stats();
        $out = [];
        foreach (array_slice($this->completedPeriods(array_keys($stats)), 0, $limit) as $period) {
            $cells = $stats[$period];
            $total = array_sum(array_column($cells, 'n'));
            $row = ['key' => $period, 'label' => MoodCheckIns::periodLabel($period), 'respondents' => null, 'average' => null, 'distribution' => null, 'happy_share' => null, 'groups' => [], 'reasons' => null];
            if ($total >= $k) {
                $distribution = array_fill_keys(array_keys(MoodCheckIn::MOODS), 0);
                foreach ($cells as $cell) {
                    foreach ($cell['moods'] as $mood => $n) {
                        $distribution[$mood] += $n;
                    }
                }
                $sum = array_sum(array_column($cells, 'sum'));
                $row = ['respondents' => $total, 'average' => round($sum / $total, 2), 'distribution' => $distribution,
                    'happy_share' => round(100 * ($distribution[4] + $distribution[5]) / $total, 1), 'reasons' => $this->reasonCounts($period)] + $row;
                $named = array_filter(array_map(fn ($c) => $c['n'], $cells), fn ($n, $key) => $key !== '', ARRAY_FILTER_USE_BOTH);
                $visible = $this->analytics->visible($named, $total, $k);
                foreach ($named as $key => $n) {
                    $shown = in_array((string) $key, $visible, true);
                    $row['groups'][] = ['key' => (string) $key, 'label' => $this->groups->label((string) $key), 'respondents' => $shown ? $n : null,
                        'average' => $shown ? round($cells[$key]['sum'] / $n, 2) : null, 'suppressed' => ! $shown];
                }
                usort($row['groups'], fn ($a, $b) => strcmp($a['label'], $b['label']));
            }
            $out[] = $row;
        }

        return ['k' => $k, 'text_k' => $this->analytics->textK(), 'frequency' => $this->moods->frequency(), 'privacy' => $this->moods->privacy(),
            'current' => ['key' => $current = $this->moods->currentPeriod(), 'label' => MoodCheckIns::periodLabel($current)], 'periods' => $out];
    }

    /**
     * Comments of one period that has ended: overall only, above the text threshold, shuffled, for engagement.comments.
     *
     * @return list<array{text: string, handle: ?string}>|null null = suppressed
     */
    public function comments(User $viewer, string $period): ?array
    {
        if (! $this->canView($viewer) || ! $viewer->hasPermission('engagement.comments')) {
            throw new EngagementRuleViolation('You cannot read the mood comments.');
        }
        if (! in_array($period, $this->completedPeriods([$period]), true) || MoodCheckIn::query()->where('period_key', $period)->count() < $this->analytics->textK()) {
            return null;
        }
        $handles = $viewer->hasPermission('engagement.confidential_identity');
        $rows = MoodCheckIn::query()->where('period_key', $period)->whereNotNull('comment')->limit(2000)->get(['id', 'comment', 'privacy'])
            ->map(fn (MoodCheckIn $c) => ['text' => (string) $c->comment, 'handle' => $handles && $c->privacy === 'confidential' ? (string) $c->id : null])
            ->filter(fn ($row) => $row['text'] !== '')->values()->all();
        if (count($rows) < $this->analytics->textK()) {
            return null;
        }
        shuffle($rows);

        return $rows;
    }

    /**
     * Identified check-ins (only those the organisation chose to identify), for engagement.responses, within the
     * viewer's access scope, newest first.
     *
     * @return list<array{employee: Employee, period: string, mood: int, reasons: list<string>, comment: ?string, on: ?string}>
     */
    public function identified(User $viewer, int $limit = 50): array
    {
        if (! $viewer->hasPermission('engagement.responses')) {
            return [];
        }
        $rows = MoodCheckIn::query()->where('privacy', 'identified')->whereNotNull('employee_id')
            ->when($this->scopes->isScoped($viewer), fn (Builder $q) => $q->whereIn('employee_id', $this->scopes->employeeKeys($viewer)))
            ->orderByDesc('checked_in_on')->limit($limit)->get();
        $employees = AccessScope::withoutScoping(fn () => Employee::query()->with('person')->whereKey($rows->pluck('employee_id')->unique()->all())->get()->keyBy('id'));
        $reasons = MoodReason::query()->pluck('name', 'id');

        return $rows->filter(fn (MoodCheckIn $c) => $employees->has($c->employee_id))->map(fn (MoodCheckIn $c) => [
            'employee' => $employees->get($c->employee_id), 'period' => MoodCheckIns::periodLabel($c->period_key), 'mood' => (int) $c->mood,
            'reasons' => collect((array) $c->reason_ids)->map(fn ($id) => $reasons[$id] ?? null)->filter()->values()->all(), 'comment' => $c->comment,
            'on' => $c->checked_in_on?->toDateString(),
        ])->values()->all();
    }

    /**
     * The (period, group) cells the happiness index dataset may show: periods that have ended, k check-ins overall, each
     * group with k check-ins after complementary suppression ('' = check-ins without a group key).
     *
     * @return list<array{0: string, 1: string}>
     */
    public function visibleCells(): array
    {
        $k = $this->k();
        $stats = $this->stats();
        $cells = [];
        foreach ($this->completedPeriods(array_keys($stats)) as $period) {
            $counts = array_map(fn ($c) => $c['n'], $stats[$period]);
            $total = array_sum($counts);
            if ($total < $k) {
                continue;
            }
            foreach ($this->analytics->visible(array_combine(array_map('strval', array_keys($counts)), array_values($counts)), $total, $k) as $group) {
                $cells[] = [$period, $group];
            }
        }

        return $cells;
    }

    /** @return array<string, array<string, array{n: int, sum: int, moods: array<int, int>}>> period => group key ('' = none) => counts */
    private function stats(): array
    {
        $out = [];
        $rows = MoodCheckIn::query()->selectRaw('period_key, group_key, mood, count(*) as n')->groupBy('period_key', 'group_key', 'mood')->toBase()->get();
        foreach ($rows as $row) {
            $cell = &$out[(string) $row->period_key][(string) $row->group_key];
            $cell ??= ['n' => 0, 'sum' => 0, 'moods' => []];
            $cell['n'] += (int) $row->n;
            $cell['sum'] += (int) $row->n * (int) $row->mood;
            $cell['moods'][(int) $row->mood] = (int) $row->n;
            unset($cell);
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string> the keys of periods that have ended, newest first
     */
    private function completedPeriods(array $keys): array
    {
        $today = $this->moods->today()->toDateString();
        $ends = [];
        foreach ($keys as $key) {
            $end = MoodCheckIns::periodEnd((string) $key);
            if ($end !== null && $end->toDateString() < $today) {
                $ends[(string) $key] = $end->toDateString();
            }
        }
        arsort($ends);

        return array_keys($ends);
    }

    /** @return array<string, int> reason => times picked in the period, most picked first */
    private function reasonCounts(string $period): array
    {
        $names = MoodReason::query()->pluck('name', 'id');
        $counts = [];
        foreach (MoodCheckIn::query()->where('period_key', $period)->whereNotNull('reason_ids')->pluck('reason_ids') as $ids) {
            foreach ((array) (is_string($ids) ? json_decode($ids, true) : $ids) as $id) {
                if (isset($names[$id])) {
                    $counts[$names[$id]] = ($counts[$names[$id]] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return $counts;
    }
}
