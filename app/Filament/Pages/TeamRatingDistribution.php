<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\PerformanceDistribution;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Performance\Services\RatingNormalisation;
use App\Filament\Support\NormaliseActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HROne parity GO-3 (matrix 27 §27.12 #3, HROne Team → Analytics → Performance): a manager's read-only view of their own
 * team's rating distribution in a cycle — the system-calculated rating and the rating that stands (final, else normalised,
 * else system) per level, against the bell curve targets (PerformanceDistribution::team). Only the people the manager
 * manages (performance.team, the configured performance-manager relationships), only ratings the performance policy shows
 * them, each curve suppressed below `performance.analytics_min_group`; names only of the people counted, alphabetically,
 * never with a rating or a level (no rankings, ANA-1).
 *
 * While the organisation lets managers normalise their team (`performance.normalise.by_managers`, owner decision PER-2 /
 * D-GO2, off by default) the page also offers "Normalise my team's ratings" (the normalise grid over the team; refusals as
 * on the board). Off: the page only reads.
 */
class TeamRatingDistribution extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Team distribution';

    protected static ?string $title = 'Team rating distribution';

    protected static ?int $navigationSort = 27;

    protected static ?string $slug = 'team-distribution';

    protected string $view = 'filament.pages.team-rating-distribution';

    #[Url]
    public ?int $cycle = null;

    /** Per-request memo (header-action closures are evaluated several times per render). */
    private ?array $distribution = null;

    /** @var list<int>|null per-request memo of the viewer's team */
    private ?array $team = null;

    /** @var array<int, string>|null per-request memo of the cycle options */
    private ?array $options = null;

    public function getSubheading(): ?string
    {
        return 'How your team\'s ratings are spread in a cycle.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('performance.team') ?? false;
    }

    public function mount(): void
    {
        // A custom page's mount() runs before Filament's access hook: refuse before doing any work.
        abort_unless(static::canAccess(), 403);
        $this->cycle ??= array_key_first($this->cycleOptions());
    }

    /** @return list<int> the employees the viewer manages (never the viewer) */
    public function teamIds(): array
    {
        if ($this->team === null) {
            $relationships = app(PerformanceRelationships::class);
            $me = $relationships->forUser(auth()->user());
            $this->team = $relationships->teamOf(auth()->user())->map(fn ($id) => (int) $id)->reject(fn (int $id) => $me !== null && $id === (int) $me->id)->values()->all();
        }

        return $this->team;
    }

    /** @return array<int, string> cycles (running, closed or archived) in which the team has appraisals, latest first */
    public function cycleOptions(): array
    {
        $team = $this->teamIds();

        return $this->options ??= $team === [] ? [] : PerformanceCycle::query()->whereIn('status', ['active', 'closed', 'archived'])
            ->whereIn('id', Appraisal::query()->whereIn('employee_id', $team)->where('status', '!=', 'withdrawn')->select('performance_cycle_id'))
            ->orderByDesc('period_start')->orderByDesc('id')->pluck('name', 'id')->all();
    }

    public function getCycle(): ?PerformanceCycle
    {
        return $this->cycle && array_key_exists($this->cycle, $this->cycleOptions()) ? PerformanceCycle::query()->with('scale')->find($this->cycle) : null;
    }

    /** @return array<string, mixed>|null */
    public function getDistribution(): ?array
    {
        $cycle = $this->getCycle();

        return $cycle === null ? null : ($this->distribution ??= app(PerformanceDistribution::class)->team($cycle, auth()->user()));
    }

    /** The manager's team in the cycle (the normalise grid's candidates). */
    public function teamEmployees(): Builder
    {
        return Employee::query()->whereIn('id', $this->teamIds() ?: [0])
            ->whereIn('id', Appraisal::query()->where('performance_cycle_id', $this->cycle ?? 0)->where('status', '!=', 'withdrawn')->select('employee_id'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pick')->label('Choose cycle')->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->visible(fn () => count($this->cycleOptions()) > 1)
                ->schema([Select::make('cycle')->options(fn () => $this->cycleOptions())->default(fn () => $this->cycle)->required()])
                ->action(function (array $data) {
                    $this->cycle = (int) $data['cycle'];
                    $this->distribution = null;
                }),
            // Owner decision PER-2 / D-GO2 (off by default): managers normalise their own reports' ratings.
            NormaliseActions::ratings('normaliseTeam', fn () => $this->getCycle(), fn () => $this->teamEmployees(), 'My whole team',
                fn () => app(RatingNormalisation::class)->byManagers()),
        ];
    }
}
