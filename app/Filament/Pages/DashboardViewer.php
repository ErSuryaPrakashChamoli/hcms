<?php

namespace App\Filament\Pages;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Services\Dashboards;
use App\Domain\Analytics\Services\DashboardViews;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Renders the dashboards the viewer is entitled to (§85). */
class DashboardViewer extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Dashboards';

    protected static ?string $title = 'Dashboards';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.dashboard-viewer';

    #[Url]
    public ?string $dashboard = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('analytics.view') ?? false;
    }

    public function mount(): void
    {
        $this->dashboard ??= app(Dashboards::class)->forUser(auth()->user())->first()?->slug;
    }

    public function getDashboard(): ?Dashboard
    {
        return $this->dashboard ? app(Dashboards::class)->forUser(auth()->user())->firstWhere('slug', $this->dashboard) : null;
    }

    public function getWidgets(): array
    {
        $dashboard = $this->getDashboard();

        return $dashboard ? app(Dashboards::class)->render($dashboard, auth()->user()) : [];
    }

    public function getTitle(): string
    {
        return $this->getDashboard()?->name ?? 'Dashboards';
    }

    /** HROne parity CA-10: a shared view says which part of the organisation it shows and when its payroll figures moved. */
    public function getSubheading(): ?string
    {
        $dashboard = $this->getDashboard();
        if ($dashboard === null || ! $dashboard->isView()) {
            return null;
        }
        $parts = ['Applicable on: '.app(DashboardViews::class)->describe($dashboard)];
        if (($asOf = $dashboard->payrollAsOf()) !== null) {
            $parts[] = 'Payroll figures as of '.$asOf->toFormattedDateString().' (they move on day '.$dashboard->refresh_day.' of each month)';
        }
        $parts[] = 'Figures follow your own access';

        return implode(' · ', $parts);
    }

    protected function getHeaderActions(): array
    {
        $available = app(Dashboards::class)->forUser(auth()->user());

        return [
            Action::make('switch')->label('Switch dashboard')->icon(Heroicon::OutlinedSquares2x2)
                ->visible(fn () => $available->count() > 1)
                ->schema([Select::make('dashboard')->options($available->pluck('name', 'slug')->all())->default($this->dashboard)->required()])
                ->action(fn (array $data) => $this->dashboard = $data['dashboard']),
        ];
    }

    public static function formatValue(mixed $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'percent' => number_format((float) $value, 1).'%',
            'currency' => number_format((float) $value, 0),
            default => is_float($value) && fmod($value, 1) != 0 ? number_format($value, 1) : number_format((float) $value, 0),
        };
    }
}
