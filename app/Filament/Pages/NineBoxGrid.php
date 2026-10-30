<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Initiatives;
use App\Domain\Performance\Services\NineBox;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Performance\Support\PerformancePeriods;
use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HROne setup parity HP-8g (Performance → 9 box): the employees of a cycle on a 3 × 3 grid of performance (the rating
 * that stands, provisional until final) against potential (NineBox: the competency rating, or the talent assessment's
 * potential for people who may see talent data). HR (performance.view / calibrate) see everyone in their access scope,
 * performance managers (performance.team) the people they manage, analytics viewers (performance.analytics) counts only.
 * The bands of each axis are set per cycle or as the default (performance.manage); none = thirds of the scale. From a box,
 * an initiative can be assigned to the person (HP-8j), as HROne's 9-box dashboard offers.
 */
class NineBoxGrid extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = '9-box';

    protected static ?string $title = '9-box';

    protected static ?int $navigationSort = 26;

    protected static ?string $slug = 'nine-box';

    protected string $view = 'filament.pages.nine-box-grid';

    #[Url]
    public ?int $cycle = null;

    #[Url]
    public ?int $department = null;

    #[Url]
    public ?int $grade = null;

    /** Per-request memo of the grid (Filament evaluates header-action closures several times per render). */
    private ?array $grid = null;

    /** @var list<int>|null per-request memo: employees the viewer may assign an initiative to */
    private ?array $assignable = null;

    public static function canAccess(): bool
    {
        return NineBox::viewMode(auth()->user()) !== null;
    }

    public function mount(): void
    {
        // A custom page's mount() runs before Filament's access hook: refuse before doing any work.
        abort_unless(static::canAccess(), 403);
        $this->cycle ??= PerformancePeriods::currentCycleId(['active', 'closed'])
            ?? PerformanceCycle::query()->whereIn('status', ['active', 'closed'])->orderByDesc('period_start')->orderByDesc('id')->value('id');
    }

    public function getCycle(): ?PerformanceCycle
    {
        return $this->cycle ? PerformanceCycle::query()->with('scale')->find($this->cycle) : null;
    }

    /** @return array<string, mixed>|null */
    public function getGrid(): ?array
    {
        $cycle = $this->getCycle();
        if ($cycle === null) {
            return null;
        }

        return $this->grid ??= app(NineBox::class)->grid($cycle, auth()->user(), ['department_id' => $this->department, 'grade_id' => $this->grade]);
    }

    /** @return list<string> */
    public function getFilterLabels(): array
    {
        return array_values(array_filter([
            $this->department ? 'Department: '.Department::query()->whereKey($this->department)->value('name') : null,
            $this->grade ? 'Grade: '.Grade::query()->whereKey($this->grade)->value('name') : null,
        ]));
    }

    public function appraisalUrl(int $appraisalId): ?string
    {
        $user = auth()->user();

        return $user->hasPermission('performance.view') || NineBox::viewMode($user) === 'team' ? AppraisalResource::getUrl('view', ['record' => $appraisalId]) : null;
    }

    /**
     * HP-8j: offer "Assign initiative" on a person the viewer may assign initiatives to — HR (performance.initiatives_manage)
     * anyone on their grid (already within their access scope), a manager the people they manage; never oneself.
     * Initiatives::create checks again.
     */
    public function canAssignInitiative(int $employeeId): bool
    {
        if ($this->assignable === null) {
            $user = auth()->user();
            $relationships = app(PerformanceRelationships::class);
            $me = $relationships->forUser($user);
            $this->assignable = match (true) {
                $user->hasPermission('performance.initiatives_manage') => collect($this->getGrid()['cells'] ?? [])->flatten(1)->pluck('employee_id')->map(fn ($id) => (int) $id)->all(),
                $user->hasPermission('performance.initiatives') => $relationships->reportIds($me)->map(fn ($id) => (int) $id)->all(),
                default => [],
            };
            $this->assignable = array_values(array_diff($this->assignable, [(int) $me?->id]));
        }

        return in_array($employeeId, $this->assignable, true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pick')->label('Choose cycle')->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->schema([Select::make('cycle')->options(fn () => PerformanceCycle::query()->whereIn('status', ['active', 'closed', 'archived'])->orderByDesc('period_start')->pluck('name', 'id')->all())->default(fn () => $this->cycle)->required()])
                ->action(function (array $data) {
                    $this->cycle = (int) $data['cycle'];
                    $this->grid = null;
                }),
            Action::make('filter')->label('Filter')->icon(Heroicon::OutlinedFunnel)->color('gray')
                ->schema([
                    Select::make('department')->label('Department')->searchable()->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all())->default(fn () => $this->department),
                    Select::make('grade')->label('Grade')->searchable()->options(fn () => Grade::query()->orderBy('name')->pluck('name', 'id')->all())->default(fn () => $this->grade),
                ])
                ->action(function (array $data) {
                    $this->department = filled($data['department'] ?? null) ? (int) $data['department'] : null;
                    $this->grade = filled($data['grade'] ?? null) ? (int) $data['grade'] : null;
                    $this->grid = null;
                }),
            // HROne Setup → Performance → 9 box: where the moderate and the high band of each axis start.
            Action::make('bands')->label('Bands')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')
                ->visible(fn () => $this->getCycle() !== null && auth()->user()->can('performance.manage'))
                ->modalDescription('A value at or above a band\'s start (and below the next band) falls in that band. Without bands of its own a cycle uses the default bands, else the thirds of each scale.')
                ->schema(fn () => $this->bandFields())
                ->action(function (array $data) {
                    $cycle = ($data['scope'] ?? 'cycle') === 'cycle' ? $this->getCycle() : null;
                    PerformanceActions::run(function () use ($cycle, $data) {
                        foreach (['performance', 'potential'] as $axis) {
                            app(NineBox::class)->saveBands($cycle, $axis, (float) $data["{$axis}_moderate"], (float) $data["{$axis}_high"], auth()->user());
                        }
                    }, 'Bands saved');
                    $this->grid = null;
                }),
            Action::make('resetBands')->label('Use thirds')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                ->visible(fn () => ($cycle = $this->getCycle()) !== null && auth()->user()->can('performance.manage') && collect(app(NineBox::class)->axes($cycle))->contains('source', 'cycle'))
                ->requiresConfirmation()->modalDescription('Removes this cycle\'s own bands: it uses the default bands, else the thirds of each scale.')
                ->action(function () {
                    PerformanceActions::run(fn () => app(NineBox::class)->resetBands($this->getCycle(), auth()->user()), 'This cycle\'s bands removed');
                    $this->grid = null;
                }),
        ];
    }

    private function bandFields(): array
    {
        $axes = app(NineBox::class)->axes($this->getCycle());
        $fields = [Radio::make('scope')->label('Bands for')->required()->default('cycle')
            ->options(['cycle' => 'This cycle ('.$this->getCycle()->name.')', 'default' => 'Every cycle without bands of its own (default)'])];
        foreach ($axes as $axis => $range) {
            $fields[] = Section::make($range['label'])->description('Scale '.NineBox::format($range['min']).' to '.NineBox::format($range['max']).'.')->columns(2)->schema([
                TextInput::make("{$axis}_moderate")->label('Moderate starts at')->numeric()->required()->default($range['bands']['moderate']['from']),
                TextInput::make("{$axis}_high")->label('High starts at')->numeric()->required()->default($range['bands']['high']['from']),
            ]);
        }

        return $fields;
    }

    /** HP-8j: assign an initiative to a person on the grid. */
    public function assignInitiativeAction(): Action
    {
        return Action::make('assignInitiative')->label('Assign initiative')->icon(Heroicon::OutlinedLightBulb)
            ->modalHeading(fn (array $arguments) => 'Assign an initiative to '.(Employee::query()->with('person')->find($arguments['employee'] ?? 0)?->person?->full_name ?? 'the employee'))
            ->schema(fn (array $arguments) => InitiativeResource::detailFields((int) ($arguments['employee'] ?? 0)))
            ->action(function (array $data, array $arguments) {
                $employee = Employee::query()->findOrFail((int) ($arguments['employee'] ?? 0));
                PerformanceActions::run(fn () => app(Initiatives::class)->create($employee, $data, auth()->user()), 'Initiative assigned');
            });
    }
}
