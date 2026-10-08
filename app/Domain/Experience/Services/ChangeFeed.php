<?php

namespace App\Domain\Experience\Services;

use App\Domain\Communication\Services\Communications;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\AuthorizationContext;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Lifecycle\Support\TimelineCategories;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Pages\AnnouncementsFeed;
use App\Filament\Resources\Employees\EmployeeResource;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * UX: "What changed?" (§13). A typed, permission-aware feed over the systems of record:
 * - working-life events from the employee timeline (lifecycle, moves, reporting, exits, pay when the
 *   viewer may see pay), filtered per category exactly as the Employee 360 timeline is
 *   (TimelineCategories::hiddenFor), for the employees the viewer may already open;
 * - announcements the viewer is in the audience of (Communications::feedFor).
 * Managers without an HR lens see their own team; an employee without employee.view sees their own
 * record. Nothing is stored; every item links to its source.
 */
final class ChangeFeed
{
    /** Categories that describe a change worth surfacing (leave and documents are too routine for a feed). */
    private const CATEGORIES = ['lifecycle', 'onboarding', 'exit', 'position', 'reporting', 'compensation', 'performance', 'learning'];

    private const TYPES = [
        'lifecycle' => ['Lifecycle', 'heroicon-m-arrow-path', 'primary'], 'onboarding' => ['Joining', 'heroicon-m-user-plus', 'success'],
        'exit' => ['Exit', 'heroicon-m-arrow-right-start-on-rectangle', 'danger'], 'position' => ['Move', 'heroicon-m-arrows-right-left', 'info'],
        'reporting' => ['Reporting', 'heroicon-m-user-group', 'info'], 'compensation' => ['Pay', 'heroicon-m-banknotes', 'accent'],
        'performance' => ['Performance', 'heroicon-m-chart-bar', 'primary'], 'learning' => ['Learning', 'heroicon-m-academic-cap', 'success'],
        'announcement' => ['Announcement', 'heroicon-m-megaphone', 'warning'],
    ];

    public function __construct(private readonly RoleLens $lenses, private readonly PerformanceRelationships $relationships) {}

    /** @return Collection<int, array{type: string, label: string, icon: string, tone: string, title: string, subject: ?string, subject_id: ?int, detail: ?string, at: CarbonInterface, url: ?string}> */
    public function for(User $user, int $limit = 12, int $days = 30): Collection
    {
        $items = collect();
        try {
            $items = $items->merge($this->timeline($user, $limit * 3, $days));
        } catch (Throwable $e) {
            report($e);
        }
        try {
            $items = $items->merge($this->announcements($user, $days));
        } catch (Throwable $e) {
            report($e);
        }

        return $items->sortByDesc(fn (array $i) => $i['at']->timestamp)->take($limit)->values();
    }

    /** Business phrases for the "since your last visit" summary (singular, plural). */
    private const PHRASES = [
        'lifecycle' => ['lifecycle change', 'lifecycle changes'], 'onboarding' => ['joiner', 'joiners'], 'exit' => ['exit', 'exits'],
        'position' => ['move or promotion', 'moves and promotions'], 'reporting' => ['reporting change', 'reporting changes'],
        'compensation' => ['pay change', 'pay changes'], 'performance' => ['performance update', 'performance updates'],
        'learning' => ['learning completion', 'learning updates'], 'announcement' => ['announcement', 'announcements'],
    ];

    /**
     * UX.15: what changed since a moment (the viewer's previous Home visit), with a one-line business summary
     * ("2 moves and promotions, 1 pay change"). Without a previous visit, the last seven days.
     *
     * @return array{since: ?CarbonInterface, items: Collection<int, array<string, mixed>>, summary: string, count: int}
     */
    public function since(User $user, ?CarbonInterface $since, int $limit = 6): array
    {
        $from = ($since ?? now()->subDays(7))->copy()->startOfDay();
        $items = $this->for($user, 40, max(1, (int) ceil($from->diffInDays(now())) + 1))->filter(fn (array $i) => $i['at']->gte($from))->values();
        $summary = $items->groupBy('type')->map(function (Collection $g, string $type) {
            [$one, $many] = self::PHRASES[$type] ?? [$g->first()['label'], $g->first()['label']];

            return $g->count().' '.($g->count() === 1 ? $one : $many);
        })->values()->all();
        $text = count($summary) > 1 ? implode(', ', array_slice($summary, 0, -1)).' and '.end($summary) : ($summary[0] ?? '');

        return ['since' => $since, 'items' => $items->take($limit), 'summary' => $text, 'count' => $items->count()];
    }

    /** One change, re-resolved for the viewer (the contextual drawer never trusts an id from the browser). */
    public function find(User $user, string $id): ?array
    {
        return $this->for($user, 120, 120)->firstWhere('id', $id);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function timeline(User $user, int $limit, int $days): Collection
    {
        $me = $this->lenses->employee($user);
        $query = EmployeeTimelineEntry::query()->with(['employee.person'])->whereIn('category', self::CATEGORIES)
            ->whereDate('occurred_on', '>=', now()->subDays($days))->whereDate('occurred_on', '<=', now()->addDays(14));
        if (! $user->hasPermission('employee.view')) {
            if ($me === null) {
                return collect();
            }
            $query->where('employee_id', $me->id);
        } elseif ($this->lenses->has($user, RoleLens::MANAGER) && ! $this->lenses->has($user, RoleLens::HR) && ! $this->lenses->has($user, RoleLens::EXECUTIVE) && ! $this->lenses->has($user, RoleLens::HR_ADMIN)) {
            $query->whereIn('employee_id', [$me->id, ...$me->directReports()->currentlyEffective()->pluck('employee_id')->all()]);
        }
        $globalHidden = [];
        if (! $user->hasPermission('employee.sensitive.view')) {
            array_push($globalHidden, ...TimelineCategories::SENSITIVE);
        }
        $query->whereNotIn('category', $globalHidden)->orderByDesc('occurred_on')->orderByDesc('id')->limit($limit);

        $hiddenFor = [];
        $profiles = [];
        $entries = $query->get();

        // UX.18: one authorisation pass over the people listed. Each person's visibility and the viewer's management
        // relationship are decided by the same checks as before, from facts loaded once for this pass (it made three
        // queries per person: 469 queries for HR Home at 10,000 employees).
        return app(AuthorizationContext::class)->run(function () use ($entries, $user, &$hiddenFor, &$profiles) {
            app(AccessScopes::class)->primeEmployeeIds($user, $entries->pluck('employee_id'));

            return $this->present($entries, $user, $hiddenFor, $profiles);
        });
    }

    /**
     * @param  Collection<int, EmployeeTimelineEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function present(Collection $entries, User $user, array &$hiddenFor, array &$profiles): Collection
    {
        return $entries->filter(function (EmployeeTimelineEntry $entry) use ($user, &$hiddenFor) {
            $employee = $entry->employee;
            if ($employee === null) {
                return false;
            }
            $hiddenFor[$employee->id] ??= TimelineCategories::hiddenFor($user, $employee);

            return ! in_array($entry->category, $hiddenFor[$employee->id], true);
        })->map(function (EmployeeTimelineEntry $entry) use ($user, &$profiles) {
            [$label, $icon, $tone] = self::TYPES[$entry->category] ?? [TimelineCategories::label($entry->category), 'heroicon-m-sparkles', 'neutral'];
            $employee = $entry->employee;
            $profiles[$employee->id] ??= $user->can('view', $employee);

            return [
                'id' => 'timeline:'.$entry->id,
                'type' => $entry->category, 'label' => $label, 'icon' => $icon, 'tone' => $tone,
                'title' => $entry->title, 'subject' => $employee->display_name, 'subject_id' => $employee->id,
                'detail' => TimelineCategories::showsDescription($user, $entry->category) ? $entry->description : null,
                'at' => $entry->occurred_on,
                'url' => $profiles[$employee->id] ? EmployeeResource::getUrl('view', ['record' => $employee]).'#journey' : null,
            ];
        })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function announcements(User $user, int $days): Collection
    {
        $me = $this->lenses->employee($user);
        if ($me === null) {
            return collect();
        }
        [$label, $icon, $tone] = self::TYPES['announcement'];
        $url = AnnouncementsFeed::canAccess() ? AnnouncementsFeed::getUrl() : null;

        return app(Communications::class)->feedFor($me)->filter(fn ($a) => $a->published_at !== null && $a->published_at->gte(now()->subDays($days)))
            ->take(5)->map(fn ($a) => ['id' => 'announcement:'.$a->id, 'type' => 'announcement', 'label' => $label, 'icon' => $icon, 'tone' => $tone, 'title' => $a->title, 'subject' => null, 'subject_id' => null,
                'detail' => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags(Str::markdown((string) $a->body)))), 140), 'at' => $a->published_at, 'url' => $url])->values();
    }
}
