<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
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
use Illuminate\Database\Eloquent\Builder;
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

    public int $limit = self::PAGE;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantContext::class)->has() && ($user->hasPermission('employee.view') || app(RoleLens::class)->employee($user) !== null);
    }

    public function mount(): void
    {
        $this->display ??= app(ExperiencePreferences::class)->for(auth()->user())['density'] === 'compact' ? 'list' : 'grid';
    }

    public function getSubheading(): ?string
    {
        return auth()->user()->hasPermission('employee.view')
            ? 'Everyone you can see, with quick previews. The full register, imports and exports stay in Employees.'
            : 'Your manager, your team and you.';
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
        $this->display = $display === 'list' ? 'list' : 'grid';
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
        return $this->query()->join('people', 'people.id', '=', 'employees.person_id')->orderBy('people.first_name')->orderBy('people.last_name')
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
        $positions = fn (string $column) => EmployeePosition::query()->whereIn('employee_id', $visible)->effectiveOn()->whereNotNull($column)->distinct()->pluck($column);

        return [
            'departments' => Department::query()->whereKey($positions('department_id'))->orderBy('name')->pluck('name', 'id')->all(),
            'locations' => Location::query()->whereKey($positions('location_id'))->orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => collect(LifecycleState::cases())->reject(fn ($s) => $s === LifecycleState::Alumni)->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])->all(),
            'manager' => $this->manager ? app(PeopleVisibility::class)->query(auth()->user())->with('person')->find($this->manager)?->display_name : null,
            'show' => app(PeopleVisibility::class)->query(auth()->user())->count() > 8,
        ];
    }

    public function registerUrl(): ?string
    {
        return EmployeeResource::canAccess() ? EmployeeResource::getUrl('index') : null;
    }
}
