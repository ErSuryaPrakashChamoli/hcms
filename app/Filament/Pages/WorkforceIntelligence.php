<?php

namespace App\Filament\Pages;

use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Platform\Services\FeatureFlags;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Workforce Intelligence (§94): aggregate attrition and capacity facts, and critical-skill coverage.
 * Phase 14: the per-employee attrition-risk score was retired. PeopleOS does not score, rank or predict
 * individuals (flight risk, promotion, termination).
 */
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

    /** @return array<string, array{key: string, label: string, value: mixed, format: string, hint: ?string}> */
    public function getFacts(): array
    {
        return app(WorkforceMetrics::class)->all(['headcount', 'attrition_rate', 'exits_30d', 'exits_in_progress', 'joiners_30d', 'avg_tenure_months'], auth()->user());
    }

    public function getSkills(): array
    {
        return app(WorkforceMetrics::class)->criticalSkills(10);
    }
}
