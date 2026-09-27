<?php

namespace App\Filament\Pages;

use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\ExitInterviews;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Exit interview analytics (§61): reasons split by employee answers vs HR inference, ratings, would-rejoin. */
class ExitInsights extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Exit';

    protected static ?string $navigationLabel = 'Exit insights';

    protected static ?string $title = 'Exit insights';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.exit-insights';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('exit.interview') || auth()->user()?->can('exit.view');
    }

    public function getAnalytics(): array
    {
        return app(ExitInterviews::class)->analytics();
    }

    /** @return array<string, int> */
    public function getExitsByType(): array
    {
        return ExitCase::query()->whereIn('status', ['completed', ...ExitCase::OPEN])->get()->groupBy('type')->map->count()->all();
    }
}
