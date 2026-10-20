<?php

namespace App\Filament\Pages;

use App\Domain\Learning\Services\LearningAnalytics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Phase 8 L&D dashboard: completion, mandatory compliance, hours, certificates, skill gaps, plans, popularity; small groups suppressed. */
class LearningDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Learning & development';

    protected static ?string $slug = 'learning-dashboard';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.learning-dashboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('learning.analytics') || $user->can('learning.view') || $user->can('learning.manage'));
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        return app(LearningAnalytics::class)->summary(auth()->user()->can('learning.costs'));
    }

    /** @return list<array<string, mixed>> */
    public function getMandatory(): array
    {
        return app(LearningAnalytics::class)->mandatoryReport();
    }
}
