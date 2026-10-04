<?php

namespace App\Filament\Support\Pages\Concerns;

use App\Domain\Experience\Services\ModuleContext;
use App\Filament\Support\PeopleOsText;
use Closure;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use ReflectionFunction;

/**
 * UX.15 closure: the PeopleOS list experience for every resource list (ListRecords and ManageRecords).
 * Context first ("214 leave requests you can see · 4 waiting for a decision"), what matters as a lens,
 * then search and results. The lens only narrows the table's own query (Filament accumulates query
 * scopes), and the counts come from that same query, so nothing here can widen what a viewer sees.
 */
trait InteractsWithModuleContext
{
    /** Status lens: one status value of this module, or null for everything. */
    #[Url(as: 'lens')]
    public ?string $posLens = null;

    /** @var array<string, mixed>|null */
    protected ?array $posContext = null;

    protected bool $posLensSuspended = false;

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        $this->applyPeopleOsTableDefaults($table);

        return $table->modifyQueryUsing(function (Builder $query): Builder {
            if ($this->posLensSuspended || $this->posLens === null || ! app(ModuleContext::class)->hasStatus($query->getModel())) {
                return $query;
            }

            return $query->where($query->getModel()->qualifyColumn('status'), $this->posLens);
        });
    }

    /** @return array{total: int, sentence: string, lenses: list<array{key: string, label: string, count: int, tone: string}>, facts: list<string>, approvals: ?array{count: int, url: string}, status: bool} */
    public function moduleContext(): array
    {
        if ($this->posContext !== null) {
            return $this->posContext;
        }
        $this->posLensSuspended = true;
        try {
            $query = $this->getTable()->getQuery();
        } finally {
            $this->posLensSuspended = false;
        }
        $resource = static::getResource();
        $this->posContext = app(ModuleContext::class)->forList($query, PeopleOsText::inline((string) $resource::getTitleCaseModelLabel()), PeopleOsText::inline((string) $resource::getTitleCasePluralModelLabel()), auth()->user());
        // A lens only ever names a status this viewer can see here.
        if ($this->posLens !== null && ! collect($this->posContext['lenses'])->contains('key', $this->posLens)) {
            $this->posLens = null;
        }

        return $this->posContext;
    }

    public function getSubheading(): ?string
    {
        return $this->moduleContext()['sentence'];
    }

    /** Sentence case, as everywhere in PeopleOS ("Leave requests", not "Leave Requests"). */
    public function getTitle(): string|Htmlable
    {
        return static::$title ?? PeopleOsText::sentence((string) static::getResource()::getTitleCasePluralModelLabel());
    }

    /** The area bar already says where you are; "Leave requests › List" said nothing more. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function setPosLens(?string $status = null): void
    {
        $this->posLens = $status !== null && collect($this->moduleContext()['lenses'])->contains('key', $status) ? $status : null;
        $this->resetPage();
    }

    public function moduleContextView(): View
    {
        return view('filament.shell.module-context', ['ctx' => $this->moduleContext(), 'lens' => $this->posLens, 'plural' => PeopleOsText::inline((string) static::getResource()::getTitleCasePluralModelLabel())]);
    }

    protected function applyPeopleOsTableDefaults(Table $table): void
    {
        $plural = PeopleOsText::inline((string) static::getResource()::getTitleCasePluralModelLabel());
        // Not customised by the resource: still null, or Filament's own default closure (Table::setUp()).
        $unset = function (string $property) use ($table): bool {
            $value = (fn () => $this->{$property})->call($table);

            return $value === null || ($value instanceof Closure && str_contains(str_replace('\\', '/', (string) (new ReflectionFunction($value))->getFileName()), '/filament/tables/'));
        };

        if ($unset('emptyStateHeading')) {
            $table->emptyStateHeading(fn (): string => $this->posLens !== null || filled($this->tableSearch) || $this->hasActiveTableFilters() ? 'Nothing matches this view' : 'No '.$plural.' yet');
        }
        if ($unset('emptyStateDescription')) {
            $table->emptyStateDescription(function () use ($plural): string {
                if ($this->posLens === null && blank($this->tableSearch) && ! $this->hasActiveTableFilters()) {
                    return ucfirst($plural).' you can see will appear here.';
                }
                $lens = collect($this->moduleContext()['lenses'])->firstWhere('key', $this->posLens)['label'] ?? null;

                return $lens !== null ? 'Clear the search, the filters or the "'.$lens.'" view to see more.' : 'Clear the search or the filters to see more.';
            });
        }
        if ($unset('searchPlaceholder')) {
            $table->searchPlaceholder('Search '.$plural);
        }
        // Bounded page sizes: no "all" option, which at enterprise volume would render every record at once.
        $table->paginationPageOptions([10, 25, 50, 100]);
    }

    protected function hasActiveTableFilters(): bool
    {
        return collect($this->tableFilters ?? [])->flatten()->filter(fn ($v) => filled($v) && $v !== false)->isNotEmpty();
    }
}
