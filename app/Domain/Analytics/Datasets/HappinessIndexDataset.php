<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Engagement\Models\MoodCheckIn;
use App\Domain\Engagement\Services\GroupKeys;
use App\Domain\Engagement\Services\MoodAnalytics;
use App\Domain\Engagement\Services\MoodCheckIns;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity HP-9f: the happiness index — one row per period and group (never one per check-in), only for
 * periods that have ended and groups with at least the privacy threshold of check-ins after complementary suppression
 * (MoodAnalytics::visibleCells). No comments, no reasons per row, no person. A viewer with a restricted access scope
 * gets no rows (the index covers the whole organisation).
 */
class HappinessIndexDataset extends Dataset
{
    public function key(): string
    {
        return 'happiness_index';
    }

    public function label(): string
    {
        return 'Happiness index (mood bot)';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['engagement.analytics'];
    }

    public function fields(): array
    {
        return [
            'period' => ['label' => 'Period', 'type' => 'string', 'value' => fn (MoodCheckIn $c) => MoodCheckIns::periodLabel((string) $c->period_key)],
            'period_key' => ['label' => 'Period key', 'type' => 'string', 'value' => fn (MoodCheckIn $c) => $c->period_key],
            'period_end' => ['label' => 'Period ended', 'type' => 'date', 'value' => fn (MoodCheckIn $c) => MoodCheckIns::periodEnd((string) $c->period_key)],
            'group' => ['label' => 'Group', 'type' => 'string', 'value' => fn (MoodCheckIn $c) => app(GroupKeys::class)->label(filled($c->group_key) ? (string) $c->group_key : null)],
            'respondents' => ['label' => 'Check-ins', 'type' => 'number', 'value' => fn (MoodCheckIn $c) => (int) $c->getAttribute('respondents')],
            'average_mood' => ['label' => 'Average mood (1–5)', 'type' => 'number', 'value' => fn (MoodCheckIn $c) => round((float) $c->getAttribute('average_mood'), 2)],
            'happy_share' => ['label' => 'Happy or very happy (%)', 'type' => 'number', 'value' => fn (MoodCheckIn $c) => round(100 * (int) $c->getAttribute('happy') / max(1, (int) $c->getAttribute('respondents')), 1)],
            'unhappy_share' => ['label' => 'Unhappy or very unhappy (%)', 'type' => 'number', 'value' => fn (MoodCheckIn $c) => round(100 * (int) $c->getAttribute('unhappy') / max(1, (int) $c->getAttribute('respondents')), 1)],
        ];
    }

    public function query(): Builder
    {
        $viewer = auth()->user();
        $cells = $viewer instanceof User && app(AccessScopes::class)->isScoped($viewer) ? [] : app(MoodAnalytics::class)->visibleCells();
        $query = MoodCheckIn::query()->selectRaw('period_key, group_key, count(*) as respondents, avg(mood) as average_mood, '
            .'sum(case when mood >= 4 then 1 else 0 end) as happy, sum(case when mood <= 2 then 1 else 0 end) as unhappy')
            ->groupBy('period_key', 'group_key')->orderBy('period_key')->orderBy('group_key');
        if ($cells === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($cells) {
            foreach ($cells as [$period, $group]) {
                $q->orWhere(fn (Builder $c) => $c->where('period_key', $period)->when($group === '', fn (Builder $g) => $g->whereNull('group_key'), fn (Builder $g) => $g->where('group_key', $group)));
            }
        });
    }
}
