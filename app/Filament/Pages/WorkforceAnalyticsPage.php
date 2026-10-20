<?php

namespace App\Filament\Pages;

use App\Domain\Workforce\Services\WorkforceAnalytics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Phase 10 workforce analytics: capacity by organisation / location / job family / employment type, planned vs actual, budget vs actual (costs permission). */
class WorkforceAnalyticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?string $title = 'Workforce analytics';

    protected static ?string $slug = 'workforce-analytics';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.workforce-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('workforce.analytics') ?? false;
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        return app(WorkforceAnalytics::class)->summary(auth()->user());
    }
}
