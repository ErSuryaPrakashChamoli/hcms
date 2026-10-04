<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Experience\Services\PeopleVisibility;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Department;
use App\Domain\Workforce\Models\Position;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * UX: the organisation map (§25). A reporting tree that loads level by level (progressive), with
 * search, focus on a person (their chain opens), zoom and pan, and a department view with headcount
 * and open positions. It shows only people the viewer may see (PeopleVisibility); open positions need
 * a workforce permission. Organisation design itself stays in the Organisation Designer.
 */
class OrganisationMap extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?string $navigationLabel = 'Org map';

    protected static ?string $title = 'Organisation map';

    protected static ?string $slug = 'organisation-map';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.experience.organisation-map';

    private const CHILD_PAGE = 12;

    #[Url]
    public ?int $focus = null;

    #[Url]
    public string $mode = 'people';

    /** @var list<int> */
    public array $expanded = [];

    /** @var array<int, int> node id => how many children are shown */
    public array $shown = [];

    public string $find = '';

    /** UX.15: show relationships beyond the line (dotted, functional, matrix, support). */
    #[Url(as: 'relations')]
    public bool $relationsOn = true;

    /** Relationship type → how the map names and groups it. */
    public const RELATION_KINDS = ['dotted' => 'Dotted line', 'functional' => 'Functional', 'project' => 'Matrix', 'secondary' => 'Matrix', 'hrbp' => 'HR partner', 'mentor' => 'Mentor', 'buddy' => 'Buddy'];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TenantContext::class)->has()
            && ($user->hasPermission('employee.view') || $user->hasPermission('organisation.view') || app(RoleLens::class)->employee($user) !== null);
    }

    public function mount(): void
    {
        if ($this->focus) {
            $this->focusOn($this->focus);
        } else {
            $this->expanded = array_slice($this->roots(), 0, 3);
        }
    }

    public function getSubheading(): ?string
    {
        return 'Reporting lines you can see. Drag to pan, Ctrl + scroll or + / − to zoom, click a person to preview.';
    }

    /** @return array<int, int|null> employee id => manager id, for visible, current employees */
    #[Computed]
    public function lines(): array
    {
        $visible = $this->visibleIds();
        $managers = ReportingRelationship::query()->whereIn('employee_id', $visible)->where('is_primary', true)->currentlyEffective()
            ->pluck('manager_id', 'employee_id')->map(fn ($m) => (int) $m)->all();
        $seen = array_flip($visible);
        $out = [];
        foreach ($visible as $id) {
            $m = $managers[$id] ?? null;
            $out[$id] = $m !== null && isset($seen[$m]) ? $m : null;
        }

        return $out;
    }

    /** @var array<int, list<int>>|null manager id => direct report ids, built once per request (UX.15.20: was a scan of every line per node) */
    private ?array $children = null;

    /**
     * Relationships beyond the primary line, between people the viewer may see: for each person, who they are
     * related to and how. Effective today; read from reporting_relationships (the one source of truth).
     *
     * @return array<int, list<array{id: int, name: string, kind: string, direction: string}>>
     */
    #[Computed]
    public function relations(): array
    {
        if (! $this->relationsOn) {
            return [];
        }
        $visible = array_keys($this->lines);
        $rows = ReportingRelationship::query()->with(['employee.person', 'manager.person'])->where('is_primary', false)->whereIn('type', array_keys(self::RELATION_KINDS))
            ->whereIn('employee_id', $visible)->whereIn('manager_id', $visible)->currentlyEffective()->limit(500)->get();
        $out = [];
        foreach ($rows as $r) {
            $kind = self::RELATION_KINDS[$r->type];
            $out[$r->employee_id][] = ['id' => (int) $r->manager_id, 'name' => (string) $r->manager?->display_name, 'kind' => $kind, 'direction' => 'to'];
            $out[$r->manager_id][] = ['id' => (int) $r->employee_id, 'name' => (string) $r->employee?->display_name, 'kind' => $kind, 'direction' => 'from'];
        }

        return $out;
    }

    /** @return list<int> */
    public function roots(): array
    {
        return array_keys(array_filter($this->lines, fn ($m) => $m === null));
    }

    /** @return list<int> */
    public function childrenOf(int $id): array
    {
        if ($this->children === null) {
            $this->children = [];
            foreach ($this->lines as $employee => $manager) {
                if ($manager !== null) {
                    $this->children[$manager][] = $employee;
                }
            }
        }

        return $this->children[$id] ?? [];
    }

    /** @return array<int, Employee> */
    public function nodes(array $ids): array
    {
        return Employee::query()->with(['person', 'currentPosition.designation', 'currentPosition.department'])->whereKey($ids)->get()->keyBy('id')->all();
    }

    public function toggle(int $id): void
    {
        if (! array_key_exists($id, $this->lines)) {
            return;
        }
        $this->expanded = in_array($id, $this->expanded, true) ? array_values(array_diff($this->expanded, [$id])) : [...$this->expanded, $id];
    }

    public function showMore(int $id): void
    {
        $this->shown[$id] = ($this->shown[$id] ?? self::CHILD_PAGE) + self::CHILD_PAGE;
    }

    public function shownFor(int $id): int
    {
        return $this->shown[$id] ?? self::CHILD_PAGE;
    }

    public function focusOn(int $id): void
    {
        if (! array_key_exists($id, $this->lines)) {
            $this->focus = null;

            return;
        }
        $this->focus = $id;
        $this->mode = 'people';
        $chain = [];
        $cursor = $id;
        $guard = 0;
        while ($cursor !== null && $guard++ < 50) {
            $chain[] = $cursor;
            $cursor = $this->lines[$cursor] ?? null;
        }
        $this->expanded = array_values(array_unique([...$this->expanded, ...$chain]));
        $this->find = '';
        $this->dispatch('pos-org-focus', id: $id);
    }

    public function expandAll(): void
    {
        $this->expanded = array_keys(array_filter(array_count_values(array_filter($this->lines)), fn ($n) => $n > 0));
    }

    public function collapseAll(): void
    {
        $this->expanded = [];
    }

    /** @return list<array{id: int, name: string, meta: ?string}> */
    #[Computed]
    public function matches(): array
    {
        if (mb_strlen(trim($this->find)) < 2) {
            return [];
        }

        return PeopleVisibility::matchName(app(PeopleVisibility::class)->query(auth()->user())->with(['person', 'currentPosition.designation'])->whereKey(array_keys($this->lines)), preg_replace('/[%_\\\\]+/', ' ', $this->find))
            ->limit(6)->get()->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->display_name, 'meta' => $e->currentPosition?->designation?->name])->all();
    }

    /** @return list<array{id: int, name: string, headcount: int, open: ?int}> */
    #[Computed]
    public function departments(): array
    {
        $visible = $this->visibleIds();
        $counts = EmployeePosition::query()->whereIn('employee_id', $visible)->effectiveOn()->whereNotNull('department_id')
            ->selectRaw('department_id, count(distinct employee_id) as n')->groupBy('department_id')->pluck('n', 'department_id')->all();
        $vacancies = (auth()->user()->hasPermission('workforce.view') || auth()->user()->hasPermission('workforce.manage'))
            ? Position::query()->where('status', 'open')->whereNotNull('department_id')->selectRaw('department_id, count(*) as n')->groupBy('department_id')->pluck('n', 'department_id')->all()
            : null;
        $ids = array_unique([...array_keys($counts), ...array_keys($vacancies ?? [])]);

        // Team peek: a few people per department, from the people the viewer may already see.
        $peek = EmployeePosition::query()->with('employee.person')->whereIn('employee_id', $visible)->effectiveOn()->whereIn('department_id', $ids)->limit(400)->get()
            ->groupBy('department_id')->map(fn ($rows) => $rows->take(6)->map(fn ($p) => ['id' => (int) $p->employee_id, 'name' => (string) $p->employee?->display_name])->values()->all());

        return Department::query()->whereKey($ids)->orderBy('name')->get()->map(fn (Department $d) => [
            'id' => $d->id, 'name' => $d->name, 'headcount' => (int) ($counts[$d->id] ?? 0), 'open' => $vacancies === null ? null : (int) ($vacancies[$d->id] ?? 0),
            'people' => $peek[$d->id] ?? [],
        ])->sortByDesc('headcount')->values()->all();
    }

    /** @return list<int> */
    private function visibleIds(): array
    {
        return app(PeopleVisibility::class)->query(auth()->user())
            // Current reporting lines: people not yet joined (their line starts on the joining date) are not on the map yet.
            ->whereNotIn('lifecycle_state', [LifecycleState::Exited->value, LifecycleState::Alumni->value, LifecycleState::PreEmployee->value, LifecycleState::Preboarding->value])
            ->pluck('employees.id')->map(fn ($id) => (int) $id)->all();
    }
}
