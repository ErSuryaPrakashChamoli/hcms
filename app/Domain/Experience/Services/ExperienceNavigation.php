<?php

namespace App\Domain\Experience\Services;

use App\Domain\Identity\Models\User;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * UX: the primary navigation. Nine sections instead of thirty module groups. Each section appears only
 * when the viewer can open at least one destination in it, so the navigation adapts to the person's
 * role without a role switch. Modules stay reachable through the section's context rail (child items),
 * the in-page space bar, the Admin Centre and the command center. Every destination is checked with
 * its own canAccess(): the navigation grants nothing.
 */
final class ExperienceNavigation
{
    public const SECTIONS = [
        'home' => ['label' => 'Home', 'icon' => Heroicon::OutlinedHome, 'active' => Heroicon::Home],
        'work' => ['label' => 'My work', 'icon' => Heroicon::OutlinedInboxStack, 'active' => Heroicon::InboxStack],
        'people' => ['label' => 'People', 'icon' => Heroicon::OutlinedUsers, 'active' => Heroicon::Users],
        'organisation' => ['label' => 'Organisation', 'icon' => Heroicon::OutlinedBuildingOffice2, 'active' => Heroicon::BuildingOffice2],
        'insights' => ['label' => 'Insights', 'icon' => Heroicon::OutlinedPresentationChartLine, 'active' => Heroicon::PresentationChartLine],
        'workflows' => ['label' => 'Workflows', 'icon' => Heroicon::OutlinedArrowsRightLeft, 'active' => Heroicon::ArrowsRightLeft],
        'services' => ['label' => 'Services', 'icon' => Heroicon::OutlinedLifebuoy, 'active' => Heroicon::Lifebuoy],
        'communication' => ['label' => 'Communication', 'icon' => Heroicon::OutlinedMegaphone, 'active' => Heroicon::Megaphone],
        'admin' => ['label' => 'Admin', 'icon' => Heroicon::OutlinedCog6Tooth, 'active' => Heroicon::Cog6Tooth],
    ];

    /** Space (ModuleCatalogue) → section. */
    public const SPACE_SECTION = [
        'You' => 'home',
        'Lifecycle' => 'people', 'Time & leave' => 'people', 'Growth' => 'people', 'Pay' => 'people',
        'Organisation' => 'organisation',
        'Insights' => 'insights',
        'Workflows' => 'workflows',
        'Services' => 'services',
        'Communication' => 'communication',
        'Setup' => 'admin', 'Platform' => 'admin', 'Other' => 'admin',
    ];

    /**
     * Modules that belong to a different section than their space suggests (class basename → section).
     * A manager's team pages are people work; the task inbox is the viewer's own work.
     */
    public const MODULE_SECTION = [
        'TaskInbox' => 'work', 'TeamRequests' => 'work', 'Approvals' => 'work', 'MyWork' => 'work',
        'MyTeam' => 'people', 'TeamCareer' => 'people', 'TeamLearning' => 'people', 'TeamWorkforce' => 'people', 'People' => 'people',
        'OrganisationMap' => 'organisation',
        'AnnouncementsFeed' => 'communication',
        'MyHr' => 'services',
        'AdminCentre' => 'admin',
        'Home' => 'home', 'Dashboard' => 'home',
        'NotificationCenter' => 'work',
    ];

    /** Modules that form their own area in a section's context rail (class basename → area label). */
    public const MODULE_AREA = [
        'People' => 'Directory', 'MyTeam' => 'My team', 'TeamCareer' => 'My team', 'TeamLearning' => 'My team', 'TeamWorkforce' => 'My team',
        'OrganisationMap' => 'Org map', 'AnnouncementsFeed' => 'Announcements', 'MyHr' => 'My HR', 'AdminCentre' => 'All modules',
    ];

    /** Preferred landing per section, by class basename; the first one the viewer can open wins. */
    public const LANDING = [
        'home' => ['Home', 'Dashboard'],
        'work' => ['MyWork', 'TaskInbox'],
        'people' => ['People', 'EmployeeResource', 'MyTeam'],
        'organisation' => ['OrganisationMap', 'OrganisationDesigner'],
        'insights' => ['WorkforceCommandCentre', 'ChangeIntelligencePage', 'PeopleAnalyticsPage'],
        'workflows' => ['WorkflowResource', 'PolicyResource'],
        'services' => ['MyHr', 'TicketResource', 'ArticleResource'],
        'communication' => ['AnnouncementsFeed', 'AnnouncementResource', 'SurveyResource'],
        'admin' => ['AdminCentre'],
    ];

    /** Preferred landing per space or group (the context rail opens it). */
    private const AREA_LANDING = [
        'Lifecycle' => ['EmployeeResource', 'OnboardingPlanResource'],
        'Time & leave' => ['LeaveRequestResource', 'AttendanceRecordResource', 'LeaveCalendar'],
        'Growth' => ['AppraisalResource', 'GoalResource', 'LearningDashboard', 'TalentDashboard'],
        'Pay' => ['PayrollControlRoom', 'PayrollRunResource', 'CompensationChangeResource', 'PayslipResource'],
        'Organisation' => ['OrganisationDesigner', 'DepartmentResource'],
        'Workforce' => ['WorkforceDashboard', 'PositionResource'],
        'Analytics' => ['WorkforceCommandCentre', 'PeopleAnalyticsPage', 'ReportResource'],
        'Audit' => ['ChangeIntelligencePage', 'AuditEventResource'],
        'Service Desk' => ['TicketResource', 'ServiceDefinitionResource'],
        'Setup' => ['ConfigurationFinder', 'TenantSettingResource'],
        'Platform' => ['IntegrationSystemResource', 'SecurityPolicyPage'],
        'My team' => ['MyTeam'],
    ];

    public function __construct(private readonly ModuleCatalogue $catalogue) {}

    /** @return list<NavigationItem> */
    public function items(User $user): array
    {
        $items = [];
        $sort = 0;
        $current = $this->currentSection($user);
        foreach (self::SECTIONS as $key => $section) {
            $landing = $this->landing($user, $key);
            if ($landing === null) {
                continue;
            }
            $children = $this->children($user, $key);
            $item = NavigationItem::make($section['label'])
                ->key('pos-'.$key)
                ->icon($section['icon'])
                ->activeIcon($section['active'])
                ->url($landing['url'])
                ->sort($sort++)
                ->isActiveWhen(fn () => $current === $key && ! $this->anyChildActive($children));
            if ($key === 'work' && ($count = $this->workCount($user)) > 0) {
                $item->badge((string) min($count, 99), 'primary');
            }
            if (count($children) > 1) {
                $item->childItems(array_map(fn (array $child) => NavigationItem::make($child['label'])
                    ->key('pos-'.$key.'-'.md5($child['label']))
                    ->url($child['url'])
                    ->isActiveWhen(fn () => $child['active']), $children));
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return list<string> sections the viewer can open, in order */
    public function sections(User $user): array
    {
        return array_values(array_filter(array_keys(self::SECTIONS), fn (string $key) => $this->landing($user, $key) !== null));
    }

    /** @return array{label: string, url: string}|null */
    public function landing(User $user, string $section): ?array
    {
        $modules = $this->modulesIn($user, $section);
        foreach (self::LANDING[$section] ?? [] as $basename) {
            foreach ($modules as $module) {
                if (class_basename($module['key']) === $basename) {
                    return ['label' => $module['label'], 'url' => $module['url']];
                }
            }
        }
        $first = $modules[0] ?? null;

        return $first ? ['label' => $first['label'], 'url' => $first['url']] : null;
    }

    public function sectionOf(array $module): string
    {
        return self::MODULE_SECTION[class_basename($module['key'])] ?? self::SPACE_SECTION[$module['space']] ?? 'admin';
    }

    /** @return list<array<string, mixed>> */
    public function modulesIn(User $user, string $section): array
    {
        return array_values(array_filter($this->catalogue->for($user), fn (array $module) => $this->sectionOf($module) === $section));
    }

    /** The module the current request is on, if any. */
    public function currentModule(User $user): ?array
    {
        $route = (string) Route::currentRouteName();
        if ($route === '') {
            return null;
        }
        $best = null;
        foreach ($this->catalogue->for($user) as $module) {
            $match = $module['kind'] === 'page' ? $route === $module['route'] : str_starts_with($route, $module['route']);
            if ($match && ($best === null || strlen($module['route']) > strlen($best['route']))) {
                $best = $module;
            }
        }

        return $best;
    }

    public function currentSection(User $user): ?string
    {
        $module = $this->currentModule($user);

        return $module ? $this->sectionOf($module) : null;
    }

    /**
     * The context rail of a section: its spaces when it spans several, otherwise its groups. Each area
     * opens its preferred module. The Home and My work sections list their own pages.
     *
     * @return list<array{label: string, url: string, active: bool, modules: list<array<string, mixed>>}>
     */
    public function children(User $user, string $section): array
    {
        $modules = $this->modulesIn($user, $section);
        if ($modules === []) {
            return [];
        }
        $current = $this->currentModule($user);
        if ($section === 'home') {
            // Personal pages live in the avatar menu (and in the command center), not in the rail.
            return [];
        }
        if ($section === 'work') {
            $order = ['MyWork' => 0, 'Approvals' => 1, 'TaskInbox' => 2, 'TeamRequests' => 3];
            usort($modules, fn ($a, $b) => ($order[class_basename($a['key'])] ?? 9) <=> ($order[class_basename($b['key'])] ?? 9));

            return array_map(fn (array $m) => ['label' => class_basename($m['key']) === 'MyWork' ? 'Inbox' : $m['label'], 'url' => $m['url'],
                'active' => $current !== null && $current['key'] === $m['key'], 'modules' => [$m]], $modules);
        }
        $plain = array_filter($modules, fn (array $m) => ! isset(self::MODULE_AREA[class_basename($m['key'])]));
        $by = count(array_unique(array_map(fn (array $m) => $m['space'], $plain))) > 1 ? 'space' : 'group';
        $areas = [];
        foreach ($modules as $module) {
            $areas[self::MODULE_AREA[class_basename($module['key'])] ?? $module[$by]][] = $module;
        }
        $out = [];
        foreach ($areas as $label => $areaModules) {
            $landing = $this->areaLanding($label, $areaModules);
            $active = $current !== null && in_array($current['key'], array_column($areaModules, 'key'), true);
            $out[] = ['label' => (string) $label, 'url' => $landing['url'], 'active' => $active, 'modules' => $areaModules, 'pinned' => in_array($label, self::MODULE_AREA, true)];
        }
        // Dedicated pages (Directory, Org map, My team) first, then the areas in catalogue order.
        $pinned = array_values(array_filter($out, fn ($a) => $a['pinned']));
        $rest = array_values(array_filter($out, fn ($a) => ! $a['pinned']));
        $out = array_map(fn ($a) => array_diff_key($a, ['pinned' => true]), [...$pinned, ...$rest]);

        return $out;
    }

    /** The modules of the area the current page belongs to (the in-page space bar). */
    public function currentArea(User $user): ?array
    {
        $section = $this->currentSection($user);
        if ($section === 'home') {
            // Personal pages (the avatar menu's "You" pages) link to each other; Home itself has no bar.
            $current = $this->currentModule($user);
            if ($current === null || in_array(class_basename($current['key']), ['Home', 'Dashboard'], true)) {
                return null;
            }
            $mine = array_values(array_filter($this->modulesIn($user, 'home'), fn (array $m) => ! in_array(class_basename($m['key']), ['Home', 'Dashboard'], true)));

            return $mine !== [] ? ['section' => 'You', 'label' => 'Your pages', 'modules' => array_map(fn ($m) => ['group' => 'You'] + $m, $mine)] : null;
        }
        if ($section === null || $section === 'work') {
            return null;
        }
        foreach ($this->children($user, $section) as $child) {
            // Even a one-module area shows the bar: it tells people where they are (section / area / page).
            if ($child['active']) {
                return ['section' => self::SECTIONS[$section]['label'], 'label' => $child['label'], 'modules' => $child['modules']];
            }
        }

        return null;
    }

    /** Items waiting for the viewer: workflow tasks plus approvals (cheap counts only). */
    public function workCount(User $user): int
    {
        try {
            return app(WorkInbox::class)->attentionCount($user);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /** @param list<array<string, mixed>> $modules */
    private function areaLanding(string $area, array $modules): array
    {
        foreach (self::AREA_LANDING[$area] ?? [] as $basename) {
            foreach ($modules as $module) {
                if (class_basename($module['key']) === $basename) {
                    return $module;
                }
            }
        }

        return $modules[0];
    }

    /** @param list<array{active: bool}> $children */
    private function anyChildActive(array $children): bool
    {
        return count($children) > 1 && in_array(true, array_column($children, 'active'), true);
    }
}
