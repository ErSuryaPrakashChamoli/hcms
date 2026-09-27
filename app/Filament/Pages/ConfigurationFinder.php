<?php

namespace App\Filament\Pages;

use App\Domain\Ai\Services\ConfigurationSearch;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** "What do you want to configure?" (§103). */
class ConfigurationFinder extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'What do you want to configure?';

    protected static ?string $title = 'What do you want to configure?';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.configuration-finder';

    #[Url]
    public string $term = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('configuration.view') || $user->can('settings.view') || $user->can('people_setup.view') || $user->can('organisation.view'));
    }

    public function getResults(): array
    {
        return app(ConfigurationSearch::class)->search($this->term);
    }
}
