<?php

namespace App\Domain\Experience\Services;

use App\Domain\Communication\Services\Communications;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
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

        return $query->get()->filter(function (EmployeeTimelineEntry $entry) use ($user, &$hiddenFor) {
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
            ->take(5)->map(fn ($a) => ['type' => 'announcement', 'label' => $label, 'icon' => $icon, 'tone' => $tone, 'title' => $a->title, 'subject' => null, 'subject_id' => null,
                'detail' => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags(Str::markdown((string) $a->body)))), 140), 'at' => $a->published_at, 'url' => $url])->values();
    }
}
