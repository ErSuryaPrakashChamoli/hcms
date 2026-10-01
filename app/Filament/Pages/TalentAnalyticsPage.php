<?php

namespace App\Filament\Pages;

use App\Domain\Talent\Services\TalentAnalytics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Phase 9 talent analytics: per-position coverage and bench strength, readiness distribution, pool
 * sizes, development-action progress, review decisions, aspiration and mobility trends, successor
 * skill gaps. Aggregates only; groups below the minimum size are suppressed; no ranking of people.
 */
class TalentAnalyticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?string $title = 'Talent analytics';

    protected static ?string $slug = 'talent-analytics';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.talent-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('talent.analytics') ?? false;
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        return app(TalentAnalytics::class)->summary();
    }

    /** @return list<array<string, mixed>> */
    public function getCoverage(): array
    {
        return app(TalentAnalytics::class)->coverage();
    }

    /** @return list<array<string, mixed>> */
    public function getGaps(): array
    {
        return app(TalentAnalytics::class)->successorGaps();
    }

    public function label(string $configKey, string $key): string
    {
        return (string) config("{$configKey}.{$key}", str_replace('_', ' ', $key));
    }
}
