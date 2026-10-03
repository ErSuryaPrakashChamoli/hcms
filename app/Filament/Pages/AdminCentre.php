<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\RoleLens;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * UX: the Admin Centre: every module the viewer can open, by section and area, with a filter. It is
 * how the thirty former sidebar groups stay discoverable once the navigation is nine sections. Each
 * module is listed only if its own canAccess() allows it.
 */
class AdminCentre extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'All modules';

    protected static ?string $title = 'Admin Centre';

    protected static ?string $slug = 'admin-centre';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.experience.admin-centre';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }
        if ($user->isPlatformAdmin()) {
            return true;
        }
        if (! app(TenantContext::class)->has()) {
            return false;
        }
        $lenses = app(RoleLens::class);

        // Never consult the module catalogue here: it calls canAccess() on every page, this one included.
        if ($lenses->has($user, RoleLens::SYSTEM_ADMIN) || $lenses->has($user, RoleLens::HR_ADMIN)) {
            return true;
        }
        foreach (['user.view', 'role.view', 'settings.view', 'configuration.view', 'integration.view', 'customfield.view', 'feature.view'] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function getSubheading(): ?string
    {
        return 'Everything you can open, by area. Press / or Ctrl K anywhere to jump straight to a module.';
    }

    /** @return array<string, array<string, list<array<string, mixed>>>> section label → group → modules */
    #[Computed]
    public function sections(): array
    {
        $nav = app(ExperienceNavigation::class);
        $out = [];
        foreach (array_keys(ExperienceNavigation::SECTIONS) as $key) {
            foreach ($nav->modulesIn(auth()->user(), $key) as $m) {
                $out[ExperienceNavigation::SECTIONS[$key]['label']][$m['group']][] = $m;
            }
        }

        return $out;
    }
}
