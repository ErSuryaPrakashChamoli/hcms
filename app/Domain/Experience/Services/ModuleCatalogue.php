<?php

namespace App\Domain\Experience\Services;

use App\Domain\Identity\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Route;
use Throwable;
use UnitEnum;

/**
 * UX: every module (Filament resource or page) the viewer may open, grouped into a few spaces.
 * Modules leave the first-level navigation and stay discoverable through the Admin Centre, the
 * command center and contextual links. Access is each module's own canAccess(): the catalogue
 * never widens it.
 */
final class ModuleCatalogue
{
    /** Filament navigation group → PeopleOS space. */
    public const SPACES = [
        'Me' => 'You',
        'People' => 'Lifecycle', 'Onboarding' => 'Lifecycle', 'Exit' => 'Lifecycle', 'Alumni' => 'Lifecycle', 'Letters' => 'Lifecycle',
        'Attendance' => 'Time & leave', 'Leave' => 'Time & leave',
        'Performance' => 'Growth', 'Learning' => 'Growth', 'Talent' => 'Growth',
        'Compensation' => 'Pay', 'Payroll' => 'Pay', 'Compliance' => 'Pay',
        'Organisation' => 'Organisation', 'Workforce' => 'Organisation', 'Assets' => 'Organisation',
        'Service Desk' => 'Services', 'Grievances' => 'Services', 'Knowledge' => 'Services',
        'Engagement' => 'Communication', 'Communication' => 'Communication',
        'Analytics' => 'Insights', 'Audit' => 'Insights',
        'Workflows' => 'Workflows', 'Policies' => 'Workflows',
        'People Setup' => 'Setup', 'Customisation' => 'Setup', 'Configuration' => 'Setup', 'Access' => 'Setup',
        'Integrations' => 'Platform', 'Enterprise' => 'Platform', 'Platform' => 'Platform',
    ];

    public const SPACE_ORDER = ['You', 'Lifecycle', 'Time & leave', 'Growth', 'Pay', 'Organisation', 'Services', 'Communication', 'Insights', 'Workflows', 'Setup', 'Platform', 'Other'];

    /** @var array<int, list<array<string, mixed>>> */
    private array $cache = [];

    /** @var array<int, true> users whose catalogue is being built (a page's canAccess() must not re-enter) */
    private array $building = [];

    /** @return list<array{key: string, label: string, group: string, space: string, icon: string|BackedEnum|null, url: string, route: string, kind: string}> */
    public function for(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }
        if (isset($this->building[$user->id])) {
            return [];
        }
        $this->building[$user->id] = true;
        try {
            return $this->cache[$user->id] = $this->build($user);
        } finally {
            unset($this->building[$user->id]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function build(User $user): array
    {
        $panel = Filament::getPanel('admin');
        $modules = [];
        foreach ($panel->getResources() as $resource) {
            /** @var class-string<resource> $resource */
            try {
                if (! $resource::shouldRegisterNavigation() || ! array_key_exists('index', $resource::getPages()) || ! $resource::canAccess()) {
                    continue;
                }
                $group = $this->groupName($resource::getNavigationGroup());
                $modules[] = ['key' => $resource, 'label' => $resource::getNavigationLabel(), 'group' => $group, 'space' => self::SPACES[$group] ?? 'Other',
                    'icon' => $resource::getNavigationIcon(), 'url' => $resource::getUrl('index'), 'route' => $resource::getRouteBaseName($panel).'.', 'kind' => 'resource'];
            } catch (Throwable) {
                continue;
            }
        }
        foreach ($panel->getPages() as $page) {
            /** @var class-string<Page> $page */
            try {
                if (! $page::shouldRegisterNavigation() || ! $page::canAccess()) {
                    continue;
                }
                $group = $this->groupName($page::getNavigationGroup());
                $modules[] = ['key' => $page, 'label' => $page::getNavigationLabel(), 'group' => $group, 'space' => self::SPACES[$group] ?? 'Other',
                    'icon' => $page::getNavigationIcon(), 'url' => $page::getUrl(), 'route' => 'filament.admin.pages.'.$page::getSlug(), 'kind' => 'page'];
            } catch (Throwable) {
                continue;
            }
        }

        return $modules;
    }

    /** @return array<string, array<string, list<array<string, mixed>>>> space → group → modules */
    public function bySpace(User $user): array
    {
        $out = [];
        foreach ($this->for($user) as $module) {
            $out[$module['space']][$module['group']][] = $module;
        }
        uksort($out, fn ($a, $b) => array_search($a, self::SPACE_ORDER, true) <=> array_search($b, self::SPACE_ORDER, true));

        return $out;
    }

    /** The space the current route belongs to (for highlighting the right workspace). */
    public function currentSpace(User $user): ?string
    {
        $route = (string) Route::currentRouteName();
        foreach ($this->for($user) as $module) {
            if ($route === $module['route'] || str_starts_with($route, $module['route'])) {
                return $module['space'];
            }
        }

        return null;
    }

    private function groupName(string|UnitEnum|null $group): string
    {
        return $group instanceof UnitEnum ? ($group instanceof BackedEnum ? (string) $group->value : $group->name) : (string) ($group ?? 'Other');
    }
}
