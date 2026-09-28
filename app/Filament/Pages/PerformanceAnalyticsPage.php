<?php

namespace App\Filament\Pages;

use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\PerformanceAnalytics;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Phase 7: aggregated performance analytics; groups below the minimum size are suppressed. */
class PerformanceAnalyticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?string $title = 'Performance analytics';

    protected static ?string $slug = 'performance-analytics';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.performance-analytics';

    #[Url]
    public ?int $cycle = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('performance.analytics') || $user->can('performance.view'));
    }

    public function mount(): void
    {
        $this->cycle ??= PerformanceCycle::query()->whereIn('status', ['active', 'closed'])->orderByDesc('period_start')->value('id');
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        return app(PerformanceAnalytics::class)->summary($this->cycle ? PerformanceCycle::query()->find($this->cycle) : null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pick')->label('Choose cycle')->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->schema([Select::make('cycle')->options(fn () => PerformanceCycle::query()->orderByDesc('period_start')->pluck('name', 'id')->all())->placeholder('All employees (no cycle)')->default(fn () => $this->cycle)])
                ->action(fn (array $data) => $this->cycle = filled($data['cycle'] ?? null) ? (int) $data['cycle'] : null),
        ];
    }
}
