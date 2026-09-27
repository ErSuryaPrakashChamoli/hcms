<?php

namespace App\Filament\Pages;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Appraisals;
use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Support\PerformanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/** Calibration board (§34): rating distribution for a cycle plus inline calibrate / finalize. */
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

    public static function canAccess(): bool
    {
        return auth()->user()?->can('performance.calibrate') ?? false;
    }

    public function mount(): void
    {
        $this->cycle ??= PerformanceCycle::query()->where('status', 'active')->orderByDesc('period_start')->value('id');
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pick')->label('Choose cycle')->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->schema([Select::make('cycle')->options(fn () => PerformanceCycle::query()->orderByDesc('period_start')->pluck('name', 'id')->all())->default(fn () => $this->cycle)->required()])
                ->action(function (array $data) {
                    $this->cycle = (int) $data['cycle'];
                    $this->resetTable();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Appraisal::query()->with(['employee.person', 'manager.person', 'reviews', 'cycle.scale'])->where('performance_cycle_id', $this->cycle ?? 0))
            ->columns(AppraisalResource::columns())
            ->defaultSort('computed_rating', 'desc')
            ->recordActions(collect(PerformanceActions::forAppraisal())->filter(fn ($a) => in_array($a->getName(), ['calibrate', 'finalize'], true))->values()->all())
            ->emptyStateHeading('No appraisals in this cycle')
            ->emptyStateDescription('Launch the cycle first, or pick another one.');
    }
}
