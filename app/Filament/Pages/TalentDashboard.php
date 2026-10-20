<?php

namespace App\Filament\Pages;

use App\Domain\Talent\Services\TalentAnalytics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Phase 9 talent dashboard: critical positions, succession coverage, ready-now coverage, review dates — facts only; people counts suppressed. */
class TalentDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Talent & succession';

    protected static ?string $slug = 'talent-dashboard';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.talent-dashboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('talent.analytics') || $user->can('succession.view') || $user->can('talent.view'));
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        return app(TalentAnalytics::class)->summary();
    }
}
