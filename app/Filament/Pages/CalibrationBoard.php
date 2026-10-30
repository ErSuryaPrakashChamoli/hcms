<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\PerformanceDistribution;
use App\Domain\Performance\Support\PerformancePeriods;
use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Calibration board (§34): rating distribution for a cycle plus inline calibrate / finalize. HROne setup parity HP-8b:
 * "Publish ratings" (manual publishing) and, with a performance year set, it opens on the cycle of the current period.
 * HP-8g (bell curve): the distribution is compared with the cycle's targets (its own, else the default ones) — target vs
 * actual per level — and can be narrowed to a department or grade; "Distribution targets" sets them (performance.manage).
 */
class CalibrationBoard extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Calibration';

    protected static ?string $title = 'Calibration board';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.calibration-board';

    #[Url]
    public ?int $cycle = null;

    /** HP-8g: narrow the board to one department / grade (the employee's current position). */
    #[Url]
    public ?int $department = null;

    #[Url]
    public ?int $grade = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('performance.calibrate') ?? false;
    }

    public function mount(): void
    {
        $this->cycle ??= PerformancePeriods::currentCycleId(['active']) ?? PerformanceCycle::query()->where('status', 'active')->orderByDesc('period_start')->value('id');
    }

    public function getCycle(): ?PerformanceCycle
    {
        return $this->cycle ? PerformanceCycle::query()->with('scale')->find($this->cycle) : null;
    }

    /** @return array<string, int> */
    public function getDistribution(): array
    {
        $cycle = $this->getCycle();

        return $cycle ? app(Appraisals::class)->distribution($cycle) : [];
    }

    /** HP-8g: actual against target per level, for the appraisals on the board (filters applied). */
    public function getComparison(): ?array
    {
        $cycle = $this->getCycle();

        return $cycle ? app(PerformanceDistribution::class)->compare($cycle, $this->filteredQuery()->get()) : null;
    }

    /** @return list<string> the active filters, for the heading */
    public function getFilterLabels(): array
    {
        return array_values(array_filter([
            $this->department ? 'Department: '.Department::query()->whereKey($this->department)->value('name') : null,
            $this->grade ? 'Grade: '.Grade::query()->whereKey($this->grade)->value('name') : null,
        ]));
    }

    private function filteredQuery(): Builder
    {
        $query = Appraisal::query()->where('performance_cycle_id', $this->cycle ?? 0);
        $positions = array_filter(['department_id' => $this->department, 'grade_id' => $this->grade]);
        if ($positions !== []) {
            $query->whereIn('employee_id', EmployeePosition::query()->effectiveOn()->where($positions)->pluck('employee_id')->all());
        }

        return $query;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pick')->label('Choose cycle')->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->schema([Select::make('cycle')->options(fn () => PerformanceCycle::query()->orderByDesc('period_start')->pluck('name', 'id')->all())->default(fn () => $this->cycle)->required()])
                ->action(function (array $data) {
                    $this->cycle = (int) $data['cycle'];
                    $this->resetTable();
                }),
            Action::make('filter')->label('Filter')->icon(Heroicon::OutlinedFunnel)->color('gray')
                ->schema([
                    Select::make('department')->label('Department')->searchable()->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all())->default(fn () => $this->department),
                    Select::make('grade')->label('Grade')->searchable()->options(fn () => Grade::query()->orderBy('name')->pluck('name', 'id')->all())->default(fn () => $this->grade),
                ])
                ->action(function (array $data) {
                    $this->department = filled($data['department'] ?? null) ? (int) $data['department'] : null;
                    $this->grade = filled($data['grade'] ?? null) ? (int) $data['grade'] : null;
                    $this->resetTable();
                }),
            // HP-8g (HROne Performance → Bell curve): the target share of employees per rating level.
            Action::make('targets')->label('Distribution targets')->icon(Heroicon::OutlinedChartBar)->color('gray')
                ->visible(fn () => $this->getCycle() !== null && auth()->user()->can('performance.manage'))
                ->modalDescription('The share of employees you aim for at each rating level (adding up to 100%). Leave every level blank to remove the targets. Whether closing a cycle checks them is chosen in Setup → Performance.')
                ->schema(fn () => $this->targetFields())
                ->action(function (array $data) {
                    $cycle = $this->getCycle();
                    $forCycle = ($data['scope'] ?? 'cycle') === 'cycle';
                    $targets = [];
                    foreach ($this->levelsFor($forCycle ? $cycle : null) as $i => $level) {
                        $targets[PerformanceDistribution::key((float) $level['value'])] = $data[$forCycle ? 'cycle_levels' : 'default_levels']["level_{$i}"] ?? null;
                    }
                    PerformanceActions::run(fn () => app(PerformanceDistribution::class)->saveTargets($forCycle ? $cycle : null, $targets, auth()->user()),
                        fn (int $n) => $n === 0 ? 'Targets removed' : 'Targets saved');
                }),
            Action::make('publishRatings')->label('Publish ratings')->icon(Heroicon::OutlinedMegaphone)->color('success')
                ->visible(fn () => ($cycle = $this->getCycle()) !== null && $cycle->status === 'active' && PerformanceActions::unpublished($cycle) > 0)
                ->requiresConfirmation()->modalDescription(fn () => PerformanceActions::unpublished($this->getCycle()).' finalized rating(s) will be shown to the employees, who are told.')
                ->action(fn () => PerformanceActions::run(fn () => app(Appraisals::class)->publish($this->getCycle(), null, auth()->user()), fn (int $n) => "{$n} rating(s) published")),
        ];
    }

    /** @return list<array<string, mixed>> the levels of the cycle's scale, or of the default scale ($cycle null) */
    private function levelsFor(?PerformanceCycle $cycle): array
    {
        $scale = $cycle?->ratingScale() ?? RatingScale::default() ?? $this->getCycle()->ratingScale();

        return collect($scale->levels)->sortBy('value')->values()->all();
    }

    /** The targets form: a share per level of this cycle's scale, or of the default scale (every cycle). */
    private function targetFields(): array
    {
        $cycle = $this->getCycle();
        $distribution = app(PerformanceDistribution::class);
        $own = $distribution->cycleTargets($cycle);
        $shared = $distribution->defaultTargets();
        $level = fn (string $scope, int $i, array $level, array $values) => TextInput::make("{$scope}_levels.level_{$i}")
            ->label(PerformanceDistribution::format((float) $level['value']).' · '.$level['label'])->numeric()->minValue(0)->maxValue(100)->suffix('%')
            ->default($values[PerformanceDistribution::key((float) $level['value'])] ?? null)
            ->visible(fn (Get $get) => $get('scope') === $scope);

        return [
            Radio::make('scope')->label('Targets for')->required()->live()->default($own === [] && $shared !== [] ? 'default' : 'cycle')
                ->options(['cycle' => 'This cycle ('.$cycle->name.')', 'default' => 'Every cycle without its own targets (default)']),
            ...collect($this->levelsFor($cycle))->map(fn (array $l, int $i) => $level('cycle', $i, $l, $own !== [] ? $own : $shared))->all(),
            ...collect($this->levelsFor(null))->map(fn (array $l, int $i) => $level('default', $i, $l, $shared))->all(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->filteredQuery()->with(['employee.person', 'manager.person', 'reviews', 'cycle.scale']))
            ->columns(AppraisalResource::columns())
            ->defaultSort('computed_rating', 'desc')
            ->recordActions(collect(PerformanceActions::forAppraisal())->filter(fn ($a) => in_array($a->getName(), ['calibrate', 'finalize', 'publishRating'], true))->values()->all())
            ->emptyStateHeading('No appraisals in this cycle')
            ->emptyStateDescription('Launch the cycle first, or pick another one.');
    }
}
