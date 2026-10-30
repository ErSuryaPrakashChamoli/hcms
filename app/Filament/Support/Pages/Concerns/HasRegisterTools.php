<?php

namespace App\Filament\Support\Pages\Concerns;

use App\Domain\Experience\Services\SavedViews;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\RegisterReport;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use RuntimeException;

/**
 * HROne parity WO-1: the register tools of a resource list page — "Views" (preset views and the person's saved views),
 * "Report" (the matching report-library template with the register's filters) and "Act on all matching" (bulk actions
 * on every record matching the filters). Opt in on a PeopleListRecords page:
 *
 *     use HasRegisterTools;
 *     public static function registerKey(): string { return 'employees'; }
 *     public static function presetViews(): array { return ['joiners' => ['label' => 'New hires', 'filters' => [...]]]; }
 *     public static function registerReports(): array { return ['employee_master']; }
 *     public static function registerBulkOperations(): array { return ['employees.initiate_exit']; }
 *     protected function getHeaderActions(): array { return [...$this->registerToolActions(), ...the page's own]; }
 *
 * and add BulkRecordActions::selected('employees', [...]) to the table's toolbarActions() for the selected rows.
 * A view only sets the register's own filters, search, sort, lens and visible columns: it narrows the register's own
 * query and can never show more than the person may see. `?view=<preset key>` opens the register with a preset view
 * (the workforce desk links use it); otherwise the person's default view applies when they open the register afresh.
 */
trait HasRegisterTools
{
    #[Url(as: 'view')]
    public ?string $posView = null;

    protected bool $posFreshOpen = false;

    /** The key saved views, bulk runs and presets are kept under. */
    public static function registerKey(): string
    {
        return str_replace(['/', '-'], '_', static::getResource()::getSlug());
    }

    /** @return array<string, array{label: string, filters?: array<string, mixed>, lens?: string|null, search?: string, sort?: string|null}> */
    public static function presetViews(): array
    {
        return [];
    }

    /** @return list<string> report library template keys offered by "Report" */
    public static function registerReports(): array
    {
        return [];
    }

    /** @return list<string> bulk operation keys offered on "Act on all matching" */
    public static function registerBulkOperations(): array
    {
        return [];
    }

    public function mountHasRegisterTools(): void
    {
        $this->posFreshOpen = ! session()->has($this->getTableFiltersSessionKey());
    }

    /** On the first render: the preset named in the URL, else (on a fresh open) the person's default view. */
    public function bootedHasRegisterTools(): void
    {
        if (! $this->shouldMountInteractsWithTable) {
            return;
        }
        $user = auth()->user();
        $preset = $this->posView !== null ? (static::presetViews()[$this->posView] ?? null) : null;
        if ($preset !== null) {
            $this->applyRegisterView($preset);
        } elseif ($this->posFreshOpen && $user !== null && ($default = app(SavedViews::class)->default($user, static::registerKey())) !== null) {
            $this->applyRegisterView($default);
        }
    }

    /** Rebuild the table after its shape changed in this request (layout, card fields). */
    protected function rebuildRegisterTable(): void
    {
        $this->table = $this->table($this->makeTable());
        $this->initTableColumnManager();
        $this->flushCachedTableRecords();
    }

    /** @return list<Action|ActionGroup> */
    protected function registerToolActions(): array
    {
        return array_values(array_filter([
            $this->savedViewsActionGroup(),
            static::registerReports() !== [] ? RegisterReport::action(static::registerReports()) : null,
            static::registerBulkOperations() !== [] ? BulkRecordActions::allMatching(static::registerKey(), static::registerBulkOperations()) : null,
        ]));
    }

    protected function savedViewsActionGroup(): ?ActionGroup
    {
        $user = auth()->user();
        if ($user === null) {
            return null;
        }
        $actions = [];
        foreach (static::presetViews() as $key => $preset) {
            $actions[] = Action::make('posPreset_'.preg_replace('/[^A-Za-z0-9_]/', '_', $key))->label($preset['label'])->icon(Heroicon::OutlinedFunnel)
                ->action(fn () => $this->applyRegisterView($preset, $preset['label']));
        }
        $saved = app(SavedViews::class)->for($user, static::registerKey());
        foreach ($saved as $view) {
            $actions[] = Action::make('posView_'.$view['id'])->label($view['name'].($view['is_default'] ? ' (default)' : ''))->icon(Heroicon::OutlinedBookmark)
                ->action(fn () => $this->applyRegisterView($view, $view['name']));
        }
        $actions[] = Action::make('posSaveView')->label('Save this view…')->icon(Heroicon::OutlinedBookmarkSquare)
            ->modalHeading('Save this view')
            ->modalDescription('Keeps the filters, search, sort and visible columns of this register under a name, for you only.')
            ->schema([
                TextInput::make('name')->required()->maxLength(60),
                Toggle::make('is_default')->label('Open this register with this view'),
            ])
            ->action(function (array $data) use ($user) {
                try {
                    app(SavedViews::class)->save($user, static::registerKey(), (string) $data['name'], $this->currentRegisterState(), (bool) ($data['is_default'] ?? false));
                    Notification::make()->success()->title('View saved')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
        if ($saved !== []) {
            $names = collect($saved)->pluck('name', 'id')->all();
            $actions[] = Action::make('posManageViews')->label('Manage saved views…')->icon(Heroicon::OutlinedCog6Tooth)
                ->fillForm(fn () => ['default' => collect($saved)->firstWhere('is_default', true)['id'] ?? null, 'delete' => []])
                ->schema([
                    Select::make('default')->label('Open the register with')->options($names)->placeholder('No default view'),
                    CheckboxList::make('delete')->label('Delete')->options($names),
                ])
                ->action(function (array $data) use ($user) {
                    $views = app(SavedViews::class);
                    $views->setDefault($user, static::registerKey(), filled($data['default'] ?? null) ? (string) $data['default'] : null);
                    foreach ((array) ($data['delete'] ?? []) as $id) {
                        $views->delete($user, static::registerKey(), (string) $id);
                    }
                    Notification::make()->success()->title('Views updated')->send();
                });
        }

        return ActionGroup::make($actions)->label('Views')->icon(Heroicon::OutlinedBookmark)->button()->color('gray');
    }

    /** @return array<string, mixed> what a saved view keeps */
    public function currentRegisterState(): array
    {
        $columns = collect($this->tableColumns ?? [])
            ->flatMap(fn (array $c) => ($c['type'] ?? null) === 'group' ? ($c['columns'] ?? []) : [$c])
            ->filter(fn (array $c) => ($c['isToggleable'] ?? false) && ($c['isToggled'] ?? false))
            ->pluck('name')->values()->all();

        return [
            'filters' => $this->tableFilters ?? [],
            'search' => (string) ($this->tableSearch ?? ''),
            'sort' => $this->tableSort,
            'lens' => property_exists($this, 'posLens') ? $this->posLens : null,
            'columns' => $columns,
            'layout' => property_exists($this, 'posLayout') ? $this->posLayout : null,
            'card_fields' => property_exists($this, 'posCardFields') ? $this->posCardFields : [],
        ];
    }

    /** @param  array<string, mixed>  $view */
    public function applyRegisterView(array $view, ?string $label = null): void
    {
        // A layout (cards / table) is part of the table's shape: rebuild it before the filters are filled.
        if (property_exists($this, 'posLayout') && in_array($view['layout'] ?? null, ['table', 'cards'], true)) {
            $changed = $this->posLayout !== $view['layout'] || ($view['card_fields'] ?? []) !== [];
            $this->posLayout = $view['layout'];
            if (($view['card_fields'] ?? []) !== []) {
                $this->posCardFields = array_values((array) $view['card_fields']);
            }
            $changed && $this->rebuildRegisterTable();
        }
        $known = array_keys($this->getTable()->getFilters());
        $filters = array_intersect_key((array) ($view['filters'] ?? []), array_flip($known));
        $this->getTableFiltersForm()->fill($filters);
        if ($this->getTable()->hasDeferredFilters()) {
            $this->tableFilters = $this->tableDeferredFilters;
        }
        $this->tableSearch = (string) ($view['search'] ?? '');
        $this->tableSort = $view['sort'] ?? null;
        if (property_exists($this, 'posLens')) {
            $this->posLens = $view['lens'] ?? null;
            $this->posContext = null;
        }
        $columns = (array) ($view['columns'] ?? []);
        if ($columns !== [] && ! $this->getTable()->hasColumnsLayout()) {
            $state = collect($this->getDefaultTableColumnState())->map(function (array $c) use ($columns) {
                if (($c['isToggleable'] ?? false) && ($c['type'] ?? null) !== 'group') {
                    $c['isToggled'] = in_array($c['name'], $columns, true);
                }

                return $c;
            })->all();
            $this->applyTableColumnManager($state);
        }
        if ($this->getTable()->persistsSearchInSession()) {
            session()->put($this->getTableSearchSessionKey(), $this->tableSearch);
        }
        if ($this->getTable()->persistsSortInSession()) {
            session()->put($this->getTableSortSessionKey(), $this->tableSort);
        }
        $this->handleTableFilterUpdates();
        $this->flushCachedTableRecords();
        if ($label !== null) {
            Notification::make()->success()->title('Showing "'.$label.'"')->send();
        }
    }
}
