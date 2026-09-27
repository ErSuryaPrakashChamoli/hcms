<?php

namespace App\Filament\Pages;

use App\Domain\Ai\Services\AttritionRisk;
use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Platform\Services\FeatureFlags;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Workforce Intelligence (§94): attrition-risk signals and critical skills, clearly labelled as inference (§95). */
class WorkforceIntelligence extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Workforce Intelligence';

    protected static ?string $title = 'Workforce Intelligence';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.workforce-intelligence';

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('ai.workforce') ?? false) && app(FeatureFlags::class)->enabled('ai.workforce_intelligence');
    }

    public function getRisk()
    {
        return app(AttritionRisk::class)->rank();
    }

    public function getSkills(): array
    {
        return app(WorkforceMetrics::class)->criticalSkills(10);
    }
}
