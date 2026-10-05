<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Experience\Services\ChangeFeed;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\PeopleVisibility;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * UX: the People directory (§24). Cards for browsing, a compact list for HR, search, filters, quick
 * preview (drawer) and links into the org map. Visibility is PeopleVisibility: the employee list as it
 * already is for people with employee.view (organisation scope applies), the viewer's own circle
 * otherwise. The full register with bulk actions and exports stays in Employees.
 */
class People extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Directory';

    protected static ?string $title = 'People';

    protected static ?string $slug = 'people';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.experience.people';

    private const PAGE = 24;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $department = null;

    #[Url]
    public ?int $location = null;

    #[Url]
    public ?string $status = null;

    #[Url]
    public ?int $manager = null;

    #[Url(as: 'view')]
    public ?string $display = null;

    /** UX.15: group the directory by department, location or manager (people stay people; groups are headings). */
    #[Url]
    public ?string $group = null;

    public int $limit = self::PAGE;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantContext::class)->has() && ($user->hasPermission('employee.view') || app(RoleLens::class)->employee($user) !== null);
    }

    public function mount(): void
    {
        // UX.16: the directory opens the way each role works with people (a URL choice always wins): HR, payroll and
        // administrators on the list (employment context), executives grouped by department, everyone else on cards.
        $experience = $this->experience();
        $this->display ??= in_array($experience, ['hr', 'payroll', 'admin'], true) || app(ExperiencePreferences::class)->for(auth()->user())['density'] === 'compact' ? 'list' : 'grid';
        if ($this->group === null && $experience === 'executive' && auth()->user()->hasPermission('employee.view')) {
            $this->group = 'department';
        }
    }

    public function experience(): string
    {
        $user = auth()->user();

        return RoleLens::experienceOf(app(RoleLens::class)->primary($user, app(ExperiencePreferences::class)->for($user)['lens'] ?? null));
    }

    /** UX.16: a manager's current reports (already visible to them), to list first. @return list<int> */
    #[Computed]
    public function team(): array
    {
        $lenses = app(RoleLens::class);
        $me = $lenses->has(auth()->user(), RoleLens::MANAGER) ? $lenses->employee(auth()->user()) : null;

        return $me ? $me->directReports()->currentlyEffective()->pluck('employee_id')->map(fn ($id) => (int) $id)->values()->all() : [];
    }

    public function getSubheading(): string|Htmlable|null
    {
        // UX.17: the hint matches the device (hover to peek with a mouse; tap on a touch screen).
        $hint = '<span class="pos-for-fine">Hover a name to peek; select it for more.</span><span class="pos-for-touch">Tap a name for more.</span>';
        if (! auth()->user()->hasPermission('employee.view')) {
            return new HtmlString('Your manager, your team and you. '.$hint);
        }
        $teamFirst = $this->team !== [] ? 'Your team comes first. ' : '';
        $f = $this->filters;

        $n = fn (int $count, string $one, string $many) => $count.' '.($count === 1 ? $one : $many);

        return new HtmlString(e(number_format($this->total).' '.($this->total === 1 ? 'person' : 'people').' you can see across '.$n(count($f['departments']), 'department', 'departments').' and '.$n(count($f['locations']), 'location', 'locations').'. '.$teamFirst).$hint);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'department', 'location', 'status', 'manager'], true)) {
            $this->limit = self::PAGE;
            unset($this->people, $this->total);
        }
    }

    public function setDisplay(string $display): void
    {
        $this->display = in_array($display, ['list', 'changed'], true) ? $display : 'grid';
    }

    public function setGroup(?string $group): void
    {
        $this->group = in_array($group, ['department', 'location', 'manager'], true) ? $group : null;
    }

    /**
     * The people shown, grouped when a grouping is chosen.
     *
     * @return array<string, Collection<int, Employee>>
     */
    public function grouped(): array
    {
        if ($this->group === null) {
            return ['' => $this->people];
        }
        $key = fn (Employee $e) => match ($this->group) {
            'department' => $e->currentPosition?->department?->name ?? 'No department',
            'location' => $e->currentPosition?->location?->name ?? 'No location',
            'manager' => $e->currentManager?->manager?->person?->display_name ? 'Reports to '.$e->currentManager->manager->person->display_name : 'No line manager',
        };

        return $this->people->groupBy($key)->sortKeys()->all();
    }

    /**
     * Recently changed: people with changes in the last 30 days that the viewer may see (ChangeFeed applies the
     * timeline categories and the people the viewer may see), newest first, one row per person.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function changed()
    {
        return app(ChangeFeed::class)->for(auth()->user(), 60, 30)->filter(fn (array $i) => $i['subject_id'] !== null)
            ->groupBy('subject_id')->map(fn ($items) => ['person_id' => $items->first()['subject_id'], 'name' => $items->first()['subject'], 'latest' => $items->first(), 'count' => $items->count()])
            ->values()->take(40);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'department', 'location', 'status', 'manager');
        $this->limit = self::PAGE;
        unset($this->people, $this->total);
    }

    public function loadMore(): void
    {
        $this->limit = min(500, $this->limit + self::PAGE);
        unset($this->people);
    }

    /** @return Builder<Employee> */
    private function query(): Builder
    {
        $query = app(PeopleVisibility::class)->query(auth()->user())
            ->with(['person', 'currentPosition.designation', 'currentPosition.department', 'currentPosition.location', 'currentManager.manager.person']);
        if (trim($this->search) !== '') {
            PeopleVisibility::matchName($query, preg_replace('/[%_\\\\]+/', ' ', mb_substr($this->search, 0, 60)));
        }
        if ($this->department) {
            $query->whereHas('currentPosition', fn (Builder $p) => $p->where('department_id', $this->department));
        }
        if ($this->location) {
            $query->whereHas('currentPosition', fn (Builder $p) => $p->where('location_id', $this->location));
        }
        if ($this->status && LifecycleState::tryFrom($this->status)) {
            $query->where('lifecycle_state', $this->status);
        } else {
            $query->where('lifecycle_state', '!=', LifecycleState::Alumni->value);
        }
        if ($this->manager) {
            $query->whereHas('reportingRelationships', fn (Builder $r) => $r->where('manager_id', $this->manager)->where('is_primary', true)->currentlyEffective());
        }

        return $query;
    }

    #[Computed]
    public function people()
    {
        $team = $this->team;

        return $this->query()->join('people', 'people.id', '=', 'employees.person_id')
            // UX.16: a manager's own team first (integer ids only; the query itself is the viewer's PeopleVisibility query).
            ->when($team !== [], fn (Builder $q) => $q->orderByRaw('CASE WHEN employees.id IN ('.implode(',', array_map('intval', $team)).') THEN 0 ELSE 1 END'))
            ->orderBy('people.first_name')->orderBy('people.last_name')
            ->select('employees.*')->limit($this->limit)->get();
    }

    #[Computed]
    public function total(): int
    {
        return $this->query()->count();
    }

    /** Filter options drawn from the people the viewer can see (never a list of the whole tenant). */
    #[Computed]
    public function filters(): array
    {
        $visible = app(PeopleVisibility::class)->query(auth()->user())->select('employees.id');
        // UX.18: departments and locations from one pass over the visible people's current positions (it was two).
        $pairs = EmployeePosition::query()->whereIn('employee_id', $visible)->effectiveOn()
            ->where(fn (Builder $q) => $q->whereNotNull('department_id')->orWhereNotNull('location_id'))
            ->distinct()->get(['department_id', 'location_id']);

        return [
            'departments' => Department::query()->whereKey($pairs->pluck('department_id')->filter()->unique()->values())->orderBy('name')->pluck('name', 'id')->all(),
            'locations' => Location::query()->whereKey($pairs->pluck('location_id')->filter()->unique()->values())->orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => collect(LifecycleState::cases())->reject(fn ($s) => $s === LifecycleState::Alumni)->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])->all(),
            'manager' => $this->manager ? app(PeopleVisibility::class)->query(auth()->user())->with('person')->find($this->manager)?->display_name : null,
            // More than eight people to filter: read nine ids at most instead of counting everyone (UX.18).
            'show' => app(PeopleVisibility::class)->query(auth()->user())->limit(9)->pluck('employees.id')->count() > 8,
        ];
    }

    public function registerUrl(): ?string
    {
        return EmployeeResource::canAccess() ? EmployeeResource::getUrl('index') : null;
    }
}
