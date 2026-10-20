<?php

namespace App\Filament\Pages;

use App\Domain\Analytics\Services\PlatformAnalytics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Phase 14 cross-domain People analytics: one factual overview across the domains, each area gated by
 * its own analytics permission, small groups suppressed, and no scoring or prediction. Detail stays on
 * each domain's own analytics page.
 */
class PeopleAnalyticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'People analytics';

    protected static ?string $title = 'People analytics';

    protected static ?string $slug = 'people-analytics';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.people-analytics';

    public string $asOf = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }
        foreach (array_keys(PlatformAnalytics::AREAS) as $area) {
            if (app(PlatformAnalytics::class)->allows($user, $area)) {
                return true;
            }
        }

        return false;
    }

    public function mount(): void
    {
        $this->asOf = now()->toDateString();
    }

    /** @return list<array{key: string, label: string, facts: array<string, mixed>, page: class-string}> */
    public function getAreas(): array
    {
        return app(PlatformAnalytics::class)->overview(auth()->user(), $this->asOf ?: null);
    }

    public function getMinGroup(): int
    {
        return app(PlatformAnalytics::class)->minGroup();
    }
}
