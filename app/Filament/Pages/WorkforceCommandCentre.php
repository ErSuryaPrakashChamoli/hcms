<?php

namespace App\Filament\Pages;

use App\Domain\Analytics\Services\WorkforceMetrics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Workforce Command Centre (§56): headcount, people cost, attrition, absenteeism, high performers, critical skills, trends. */
class WorkforceCommandCentre extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Workforce Command Centre';

    protected static ?string $title = 'Workforce Command Centre';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.workforce-command-centre';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('analytics.executive') ?? false;
    }

    public function getMetrics(): array
    {
        return app(WorkforceMetrics::class)->all(['headcount', 'people_cost', 'attrition_rate', 'absenteeism_rate', 'joiners_30d', 'exits_30d', 'high_performers', 'cost_per_head', 'avg_tenure_months', 'women_share', 'learning_completion', 'open_grievances']);
    }

    public function getTrends(): array
    {
        $m = app(WorkforceMetrics::class);

        return ['headcount' => $m->series('headcount'), 'joiners' => $m->series('joiners'), 'exits' => $m->series('exits'), 'people_cost' => $m->series('people_cost')];
    }

    public function getCriticalSkills(): array
    {
        return app(WorkforceMetrics::class)->criticalSkills();
    }
}
