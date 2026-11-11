<?php

namespace App\Domain\Experience\Services;

use App\Domain\Identity\Models\User;
use Filament\Navigation\NavigationItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
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
    /**
     * The rail's sections. Each has its own icon, drawn for its name (resources/svg/peopleos/rail-*.svg: a house, a
     * clipboard of ticked tasks, two people, an org chart, rising bars with a spark, a flow whose routes meet, a help-desk
     * headset, two speech bubbles, a gear), and its own wayfinding hue (--pos-rail-hue-*) for its tile, toggle and areas.
     */
    public const SECTIONS = [
        'home' => ['label' => 'Home', 'icon' => 'pos-rail-home', 'hue' => 'iris'],
        'work' => ['label' => 'My work', 'icon' => 'pos-rail-work', 'hue' => 'gold'],
        'people' => ['label' => 'People', 'icon' => 'pos-rail-people', 'hue' => 'blue'],
        'organisation' => ['label' => 'Organisation', 'icon' => 'pos-rail-organisation', 'hue' => 'green'],
        'insights' => ['label' => 'Insights', 'icon' => 'pos-rail-insights', 'hue' => 'plum'],
        'workflows' => ['label' => 'Workflows', 'icon' => 'pos-rail-workflows', 'hue' => 'lime'],
        'services' => ['label' => 'Services', 'icon' => 'pos-rail-services', 'hue' => 'teal'],
        'communication' => ['label' => 'Communication', 'icon' => 'pos-rail-communication', 'hue' => 'coral'],
        'admin' => ['label' => 'Admin', 'icon' => 'pos-rail-admin', 'hue' => 'slate'],
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
        // HROne parity RQ-1: the request tracker and launcher sit with the inbox (HROne's rail "Request").
        'MyRequests' => 'work', 'NewRequest' => 'work',
        // HROne parity RQ-8: the unified inbox sits with Approvals and the task inbox it reads from.
        'Inbox' => 'work',
        'MyTeam' => 'people', 'TeamCareer' => 'people', 'TeamLearning' => 'people', 'TeamWorkforce' => 'people', 'People' => 'people',
        // HROne parity CA-9: the team / HR event calendar sits with the team pages.
        'TeamCalendar' => 'people',
        // HROne parity PO-17: increment plans HR shared with a manager (Team → Action → "Increment calculator").
        'TeamIncrements' => 'people',
        'OrganisationMap' => 'organisation',
        'AnnouncementsFeed' => 'communication',
        // HROne parity GO-15: an employee's event invitations sit with the announcements they read.
        'MyEvents' => 'communication',
        'MyHr' => 'services',
        'AdminCentre' => 'admin',
        // Data import: every upload by module, and the uploads made, in the Admin section next to All modules.
        'DataImport' => 'admin', 'RecentUploads' => 'admin',
        'Home' => 'home', 'Dashboard' => 'home',
        'NotificationCenter' => 'work',
    ];

    /** Modules that form their own area in a section's context rail (class basename → area label). */
    public const MODULE_AREA = [
        'People' => 'Directory', 'MyTeam' => 'My team', 'TeamCareer' => 'My team', 'TeamLearning' => 'My team', 'TeamWorkforce' => 'My team',
        'TeamCalendar' => 'My team', 'TeamIncrements' => 'My team',
        'OrganisationMap' => 'Org map', 'AnnouncementsFeed' => 'Announcements', 'MyHr' => 'My HR', 'AdminCentre' => 'All modules',
        // HROne parity GO-15: the employee's events (invitations, answers, details) form their own area, like Announcements.
        'MyEvents' => 'My events',
        'DataImport' => 'Data import', 'RecentUploads' => 'Data import',
        // HROne parity GO-18: fundraisers are an area of the Communication section beside the announcements feed (employees
        // reach it only while a fundraiser is open to them, or they pledged to one; HR also from the Engage desk).
        'FundraiserCampaignResource' => 'Fundraisers',
    ];

    /**
     * The operations desks (HROne parity WO-1, TX-1, PO-1, GO-1, XO-1), in the order they lead an area. A desk orders the
     * registers of the area it sits in (its space, or its group in a one-space section) by the journey they serve, so it
     * is that area's front page: the area opens on it and it leads the area bar after the favourites. It is not an area
     * of its own, which listed the same registers twice in the rail (Time & leave and "Time office desk", Pay and
     * "Payroll desk"). Only operators can open a desk; everyone else opens the area as before.
     */
    public const DESKS = ['WorkforceDesk', 'TimeOfficeDesk', 'PayrollDesk', 'ExpenseDesk', 'PerformanceDesk', 'EngageDesk', 'AssetDesk'];

    /**
     * Rail names of areas that would otherwise repeat their section's name ("Organisation › Organisation" reads as one
     * level, not two). The area keeps its own name as its key: its landing (AREA_LANDING) and everyday modules
     * (EVERYDAY) stay filed under it. My work's overview page is an area of the My work section.
     */
    public const AREA_LABELS = [
        'My work' => 'Overview',
        'Organisation' => 'Structure',
        'Communication' => 'Messaging',
        'Workflows' => 'Process flows',
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

    /**
     * UX.16: section order per experience. Home first everywhere, then the person's own kind of work; every other
     * section the person can open follows (nothing is hidden to look simpler, and each still checks canAccess()).
     */
    public const ORDER = [
        'employee' => ['home', 'work', 'people', 'services', 'communication', 'organisation', 'insights', 'workflows', 'admin'],
        'manager' => ['home', 'work', 'people', 'organisation', 'insights', 'services', 'communication', 'workflows', 'admin'],
        'hr' => ['home', 'work', 'people', 'organisation', 'workflows', 'services', 'communication', 'insights', 'admin'],
        'payroll' => ['home', 'work', 'people', 'insights', 'services', 'organisation', 'workflows', 'communication', 'admin'],
        'executive' => ['home', 'insights', 'people', 'organisation', 'work', 'communication', 'services', 'workflows', 'admin'],
        'admin' => ['home', 'admin', 'organisation', 'people', 'workflows', 'insights', 'work', 'services', 'communication'],
    ];

    /** UX.16: a role's preferred landing for a section, tried before LANDING (still only what the person can open). */
    public const ROLE_LANDING = [
        'manager' => ['people' => ['MyTeam']],
        'admin' => ['organisation' => ['OrganisationDesigner'], 'insights' => ['ChangeIntelligencePage', 'AuditEventResource']],
    ];

    /** Preferred landing per space or group (the context rail opens it). */
    private const AREA_LANDING = [
        'Lifecycle' => ['EmployeeResource', 'OnboardingPlanResource'],
        // An employee's Time & leave opens on My attendance (their month, leave balance, regularise and apply leave); HR
        // opens it on the time-office desk, which comes first.
        'Time & leave' => ['AttendanceCalendar', 'LeaveRequestResource', 'AttendanceRecordResource', 'LeaveCalendar'],
        'Growth' => ['AppraisalResource', 'GoalResource', 'LearningDashboard', 'TalentDashboard'],
        'Pay' => ['PayrollControlRoom', 'PayrollRunResource', 'CompensationChangeResource', 'PayslipResource'],
        'Organisation' => ['OrganisationDesigner', 'DepartmentResource'],
        'Workforce' => ['WorkforceDashboard', 'PositionResource'],
        // Not the Workforce Command Centre: that is where the Insights section itself opens.
        'Analytics' => ['ReportResource', 'PeopleAnalyticsPage', 'DashboardViewer'],
        'Audit' => ['ChangeIntelligencePage', 'AuditEventResource'],
        'Service Desk' => ['TicketResource', 'ServiceDefinitionResource'],
        'Setup' => ['ConfigurationFinder', 'TenantSettingResource'],
        'Platform' => ['IntegrationSystemResource', 'SecurityPolicyPage'],
        'My team' => ['MyTeam'],
        'Data import' => ['DataImport', 'RecentUploads'],
    ];

    /**
     * UX.15: workspaces carry their own header (where am I, what matters, what can I do), so they show no
     * module bar. The Employee 360 is a person workspace, not a module page. The Workforce Command Centre is not
     * one: it shares the Analytics area with reports and dashboards, so it shows that area's bar.
     */
    public const WORKSPACES = ['Home', 'Dashboard', 'MyWork', 'Approvals', 'NotificationCenter', 'People', 'OrganisationMap', 'MyHr', 'AdminCentre', 'AnnouncementsFeed'];

    private const WORKSPACE_ROUTES = ['filament.admin.resources.employees.view'];

    /**
     * UX.15: the everyday modules of an area (at most six, in this order). Everything else in the area —
     * configuration, reference data and specialist tools — is one step away in "All in {area}", so the bar
     * reads as a workspace rather than a list of tables.
     */
    public const EVERYDAY = [
        'Lifecycle' => ['EmployeeResource', 'OnboardingPlanResource', 'ExitCaseResource', 'LetterResource', 'BgvCaseResource', 'AlumniProfileResource'],
        'Growth' => ['AppraisalResource', 'GoalResource', 'OneOnOneResource', 'FeedbackEntryResource', 'LearningDashboard', 'TalentDashboard'],
        'Time & leave' => ['LeaveRequestResource', 'LeaveCalendar', 'AttendanceRecordResource', 'AttendanceRegularisationResource', 'AttendanceExceptionCentre', 'LeaveBalanceResource'],
        'Pay' => ['PayrollControlRoom', 'PayrollRunResource', 'PayslipResource', 'CompensationChangeResource', 'TaxDeclarationResource', 'ComplianceControlRoom'],
        'Organisation' => ['OrganisationDesigner', 'DepartmentResource', 'LocationResource', 'TeamResource', 'CompanyResource'],
        'Workforce' => ['WorkforceDashboard', 'PositionResource', 'WorkforcePlanResource', 'VacanciesPage', 'PositionHierarchyPage'],
        'Analytics' => ['WorkforceCommandCentre', 'PeopleAnalyticsPage', 'ReportResource', 'DashboardViewer'],
        'Audit' => ['ChangeIntelligencePage', 'AuditEventResource'],
        'Service Desk' => ['TicketResource', 'ServiceDeskAnalyticsPage'],
        'Engagement' => ['SurveyResource', 'CompanyEventResource', 'EmployeeFeedbackResource', 'EngagementCampaignResource'],
        'Communication' => ['AnnouncementResource'],
        'Grievances' => ['GrievanceResource'],
        'Assets' => ['AssetResource'],
        'Setup' => ['ConfigurationFinder', 'ConfigurationChangeResource', 'UserResource', 'RoleResource', 'TenantSettingResource'],
        'Platform' => ['IntegrationSystemResource', 'SecurityPolicyPage', 'InboundEventResource', 'ApiKeyResource'],
    ];

    /** @var array<string, string> page URL → route name, for re-renders on Livewire's update route */
    private array $routes = [];

    public function __construct(private readonly ModuleCatalogue $catalogue) {}

    /** @return list<NavigationItem> */
    public function items(User $user): array
    {
        $items = [];
        $sort = 0;
        $current = $this->currentSection($user);
        $experience = $this->experience($user);
        foreach (self::ORDER[$experience] ?? array_keys(self::SECTIONS) as $key) {
            $section = self::SECTIONS[$key];
            $landing = $this->landing($user, $key, $experience);
            if ($landing === null) {
                continue;
            }
            $children = $this->children($user, $key);
            $item = NavigationItem::make($section['label'])
                ->key('pos-'.$key)
                ->icon($section['icon'])
                ->sort($sort++)
                ->isActiveWhen(fn () => $current === $key && ! $this->anyChildActive($children));
            if ($key === 'work' && ($count = $this->workCount($user)) > 0) {
                $item->badge((string) min($count, 99), 'primary');
            }
            // The rail's sidebar item view puts the section's icon on a tile in its hue.
            $attributes = ['data-pos-hue' => $section['hue']];
            if (count($children) > 1) {
                // A section of several areas is not a page: clicking it only opens its list of areas (the view draws it
                // as a button), and each area opens its own page.
                $item->extraAttributes($attributes + ['data-pos-section' => $key])
                    ->childItems(array_map(fn (array $child) => NavigationItem::make($child['label'])
                        ->key('pos-'.$key.'-'.md5($child['key']))
                        ->url($child['url'])
                        ->isActiveWhen(fn () => $child['active']), $children));
            } else {
                $item->extraAttributes($attributes)->url($landing['url']);
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return list<string> sections the viewer can open, in the order of their experience */
    public function sections(User $user): array
    {
        $experience = $this->experience($user);

        return array_values(array_filter(self::ORDER[$experience] ?? array_keys(self::SECTIONS), fn (string $key) => $this->landing($user, $key, $experience) !== null));
    }

    /** UX.16: the experience that orders the navigation (the same primary lens as Home, including "Home opens as"). */
    public function experience(User $user): string
    {
        $lenses = app(RoleLens::class);

        return RoleLens::experienceOf($lenses->primary($user, app(ExperiencePreferences::class)->for($user)['lens'] ?? null));
    }

    /** @return array{label: string, url: string}|null */
    public function landing(User $user, string $section, ?string $experience = null): ?array
    {
        $modules = $this->modulesIn($user, $section);
        foreach ([...(self::ROLE_LANDING[$experience ?? ''][$section] ?? []), ...(self::LANDING[$section] ?? [])] as $basename) {
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

    /**
     * The route of the page being shown. The area bar renders inside the page's Livewire component, so an action,
     * a filter or the next page re-renders it on Livewire's update route: the page is then the one the component
     * was mounted on (its path travels in the checksummed snapshot).
     */
    public function currentRoute(): string
    {
        if (! Livewire::isLivewireRequest()) {
            return (string) Route::currentRouteName();
        }
        $url = (string) Livewire::originalUrl();
        if (! array_key_exists($url, $this->routes)) {
            try {
                $this->routes[$url] = (string) Route::getRoutes()->match(Request::create($url))->getName();
            } catch (Throwable) {
                $this->routes[$url] = '';
            }
        }

        return $this->routes[$url];
    }

    /** The module the current request is on, if any. */
    public function currentModule(User $user): ?array
    {
        $route = $this->currentRoute();
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
     * @return list<array{key: string, label: string, url: string, active: bool, modules: list<array<string, mixed>>}> key = the area's own name, label = its rail name (AREA_LABELS)
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
            $order = ['MyWork' => 0, 'Inbox' => 1, 'Approvals' => 2, 'TaskInbox' => 3, 'TeamRequests' => 4, 'MyRequests' => 5, 'NewRequest' => 6];
            usort($modules, fn ($a, $b) => ($order[class_basename($a['key'])] ?? 9) <=> ($order[class_basename($b['key'])] ?? 9));

            // Streamline S4: one inbox. "My work" is the overview, "Inbox" acts on approvals and tasks together, so the older
            // Approvals list leaves the rail when the Inbox opens for the person (its links and notifications still open it).
            $keys = array_map(fn (array $m) => class_basename($m['key']), $modules);
            if (in_array('Inbox', $keys, true)) {
                $modules = array_values(array_filter($modules, fn (array $m) => class_basename($m['key']) !== 'Approvals'));
            }

            return array_map(fn (array $m) => ['key' => $m['label'], 'label' => self::AREA_LABELS[$m['label']] ?? $m['label'], 'url' => $m['url'],
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
            $out[] = ['key' => (string) $label, 'label' => self::AREA_LABELS[$label] ?? (string) $label, 'url' => $landing['url'], 'active' => $active,
                'modules' => $areaModules, 'pinned' => in_array($label, self::MODULE_AREA, true)];
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
        if ($section === null || $section === 'work' || $this->onWorkspace($user)) {
            return null;
        }
        foreach ($this->children($user, $section) as $child) {
            // Even a one-module area shows the bar: it tells people where they are (section / area / page).
            if ($child['active']) {
                return ['section' => self::SECTIONS[$section]['label'], 'key' => $child['key'], 'label' => $child['label'], 'modules' => $child['modules']];
            }
        }

        return null;
    }

    /** Is the current page a workspace (its own header, no module bar)? */
    public function onWorkspace(User $user): bool
    {
        if (in_array($this->currentRoute(), self::WORKSPACE_ROUTES, true)) {
            return true;
        }
        $current = $this->currentModule($user);

        return $current !== null && in_array(class_basename($current['key']), self::WORKSPACES, true);
    }

    /**
     * UX.15: the area bar of a module page: where you are, then the area's modules in one row: the page you are on
     * first, then the person's favourites, the area's desk, then its everyday modules. "All in {area}" holds the favourites in a
     * row of their own and every module of the area grouped by purpose. On a module's own page (landing) the bar
     * names the page, so the page header drops its title and its buttons join the table's toolbar.
     *
     * @return array{section: string, label: string, primary: list<array<string, mixed>>, favourites: list<array<string, mixed>>, groups: array<string, list<array<string, mixed>>>, count: int, current: ?string, current_label: ?string, landing: bool}|null
     */
    public function areaBar(User $user): ?array
    {
        $area = $this->currentArea($user);
        if ($area === null) {
            return null;
        }
        $modules = $area['modules'];
        $byKey = array_column($modules, null, 'key');
        $byBase = [];
        foreach ($modules as $m) {
            $byBase[class_basename($m['key'])] = $m;
        }
        $current = $this->currentModule($user);
        $current = $current !== null ? ($byKey[$current['key']] ?? null) : null;
        $everyday = self::EVERYDAY[$area['key'] ?? $area['label']] ?? null;
        if ($everyday === null || count($modules) <= 6) {
            $primary = $modules;
        } else {
            $primary = array_values(array_filter(array_map(fn (string $b) => $byBase[$b] ?? null, $everyday)));
            if ($primary === []) {
                $primary = array_slice($modules, 0, 6);
            }
        }
        $desks = array_values(array_filter(array_map(fn (string $b) => $byBase[$b] ?? null, self::DESKS)));
        $starred = (array) app(ExperiencePreferences::class)->for($user)['favourite_modules'];
        $favourites = array_values(array_filter($modules, fn (array $m) => in_array($m['key'], $starred, true)));
        $row = [];
        foreach ([...($current !== null ? [$current] : []), ...$favourites, ...$desks, ...$primary] as $m) {
            $row[$m['key']] ??= $m;
        }
        $groups = [];
        foreach ($modules as $m) {
            $groups[(string) $m['group']][] = $m;
        }
        $landing = $current !== null && $this->currentRoute() === ($current['kind'] === 'page' ? $current['route'] : $current['route'].'index');

        return ['section' => $area['section'], 'label' => $area['label'], 'primary' => array_values($row), 'favourites' => $favourites,
            'groups' => $groups, 'count' => count($modules), 'current' => $current['key'] ?? null, 'current_label' => $current['label'] ?? null,
            'landing' => $landing];
    }

    /** Is this a module's own page, opened from the area bar (the bar names it, so the page header steps back)? */
    public function onAreaLanding(User $user): bool
    {
        return (bool) ($this->areaBar($user)['landing'] ?? false);
    }

    /** Star or unstar a module of the person's catalogue for the area bar (false: not a module they can open). */
    public function toggleFavouriteModule(User $user, string $key): bool
    {
        if (! in_array($key, array_column($this->catalogue->for($user), 'key'), true)) {
            return false;
        }
        app(ExperiencePreferences::class)->toggleFavouriteModule($user, $key);

        return true;
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

    /**
     * An area of several modules opens a module page, never a workspace: a workspace has no area bar, so the
     * area would open on a page that does not show it. An area opens on its desk for the people who can open it.
     *
     * @param  list<array<string, mixed>>  $modules
     */
    private function areaLanding(string $area, array $modules): array
    {
        $pages = array_values(array_filter($modules, fn (array $m) => ! in_array(class_basename($m['key']), self::WORKSPACES, true)));
        $modules = count($modules) > 1 && $pages !== [] ? $pages : $modules;
        foreach ([...self::DESKS, ...(self::AREA_LANDING[$area] ?? [])] as $basename) {
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
