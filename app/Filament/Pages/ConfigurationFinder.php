<?php

namespace App\Filament\Pages;

use App\Domain\Ai\Services\ConfigurationSearch;
use App\Domain\Experience\Services\ModuleCatalogue;
use App\Filament\Resources\TenantSettings\TenantSettingResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "What do you want to configure?" (§103): every configuration screen and setting the viewer may open, by module
 * (modules down the left, the open one beside them), narrowed as they type. Each screen is listed only if the module
 * catalogue says the viewer can open it.
 */
class ConfigurationFinder extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'What do you want to configure?';

    protected static ?string $title = 'What do you want to configure?';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.configuration-finder';

    /** The modules, in order: key → [label, what it covers, icon]. Each configuration map entry names its module. */
    public const MODULES = [
        'organisation' => ['Organisation structure', 'Companies, legal entities, establishments, locations, departments, teams and cost centres.', 'heroicon-o-building-office-2'],
        'people' => ['Jobs and people data', 'Designations, levels, grades, job families, employee categories, document types, custom fields and forms.', 'heroicon-o-identification'],
        'lifecycle' => ['Joining, letters and exit', 'Onboarding and letter templates, probation, notice and clearance rules.', 'heroicon-o-arrow-path'],
        'time' => ['Time and leave rules', 'Shifts, work schedules, holidays, leave types, attendance policies and devices.', 'heroicon-o-calendar-days'],
        'pay' => ['Pay and compensation', 'Salary components and structures, pay ranges and payroll rules.', 'heroicon-o-banknotes'],
        'compliance' => ['Statutory compliance', 'Statutory profiles, registrations and rules, TDS deductors and export layouts.', 'heroicon-o-shield-check'],
        'growth' => ['Performance, learning and careers', 'Performance cycles and templates, rating scales, competencies, skills, courses and career paths.', 'heroicon-o-arrow-trending-up'],
        'service' => ['Service desk and assets', 'The HR service catalogue, ticket categories and SLAs, grievance handlers and asset categories.', 'heroicon-o-lifebuoy'],
        'workflows' => ['Workflows and notifications', 'Approval workflows, notification rules and templates, and audiences.', 'heroicon-o-arrows-right-left'],
        'access' => ['Access and security', 'Users, roles, single sign-on, the security policy and password rules.', 'heroicon-o-key'],
        'data' => ['Integrations, reports and data', 'API keys, webhooks, the integration hub, exchange rates, reports, dashboards and retention.', 'heroicon-o-circle-stack'],
        'general' => ['Company-wide settings', 'Settings, features, branding and locale, country and configuration packs, and the change centre.', 'heroicon-o-cog-6-tooth'],
    ];

    /** Tenant setting key prefix → module; the longest matching prefix wins, anything else is company-wide. */
    public const SETTING_MODULES = [
        'employee.code' => 'people', 'documents' => 'people',
        'employee' => 'lifecycle', 'exit' => 'lifecycle',
        'attendance' => 'time', 'leave' => 'time',
        'payroll' => 'pay', 'compensation' => 'pay',
        'security' => 'access',
        'retention' => 'data', 'warehouse' => 'data', 'ai' => 'data',
        // HROne setup parity HP-8a…c: the performance settings and terminology.
        'performance' => 'growth',
    ];

    #[Url]
    public string $term = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('configuration.view') || $user->can('settings.view') || $user->can('people_setup.view') || $user->can('organisation.view'));
    }

    public function getSubheading(): ?string
    {
        return 'Everything you can set up, by module. Type to narrow it down, or choose a module to see all of it.';
    }

    /**
     * The modules with what the viewer may open in each: configuration screens first, then individual settings
     * (names and keys only, never values). While searching, only matching items and their modules remain.
     *
     * @return list<array{key: string, label: string, why: string, icon: string, items: list<array{label: string, url: string, setting: bool}>}>
     */
    #[Computed]
    public function modules(): array
    {
        $user = auth()->user();
        $catalogue = app(ModuleCatalogue::class);
        $search = app(ConfigurationSearch::class);
        $term = trim($this->term);
        $matched = $term === '' ? null : array_column($search->relevant($term, PHP_INT_MAX), 'url');

        $items = [];
        foreach (config('peopleos.ai.config_search', []) as $entry) {
            $url = url($entry['url']);
            if (($matched === null || in_array($url, $matched, true)) && $catalogue->opens($user, $url)) {
                $items[$entry['module'] ?? 'general'][] = ['label' => $entry['label'], 'url' => $url, 'setting' => false];
            }
        }
        $settings = rescue(fn () => TenantSettingResource::canAccess() ? TenantSettingResource::getUrl('index') : null, null, false);
        if ($settings !== null) {
            foreach ($term === '' ? array_keys(config('peopleos.settings', [])) : $search->settings($term) as $key) {
                $items[$this->settingModule($key)][] = ['label' => ConfigurationSearch::settingLabel($key), 'url' => $settings.'?search='.urlencode($key), 'setting' => true, 'key' => $key];
            }
        }

        $out = [];
        foreach (self::MODULES as $key => [$label, $why, $icon]) {
            if (($items[$key] ?? []) !== []) {
                $out[] = ['key' => $key, 'label' => $label, 'why' => $why, 'icon' => $icon, 'items' => $items[$key]];
            }
        }

        return $out;
    }

    /**
     * The modules as browse areas (x-pos.browse): a module's screens first, then its settings by what they belong to, each
     * under its own name ("Payroll · Tax" → "Form 12BB visibility"). While searching, "All matches" comes first: every
     * matching screen (with the module it belongs to) and every matching setting.
     *
     * @return list<array{key: string, label: string, sub: string, icon: string, items: list<array<string, mixed>>, sets: list<array{label: string, items: list<array<string, mixed>>}>}>
     */
    public function browseGroups(): array
    {
        $groups = array_map(fn (array $m) => ['key' => $m['key'], 'label' => $m['label'], 'sub' => $m['why'], 'icon' => $m['icon'],
            'items' => array_values(array_filter($m['items'], fn (array $item) => ! $item['setting'])),
            'sets' => $this->settingSets(array_filter($m['items'], fn (array $item) => $item['setting']))], $this->modules);
        $term = trim($this->term);
        if ($term === '' || $groups === []) {
            return $groups;
        }
        $screens = array_merge(...array_map(fn (array $g) => array_map(fn (array $item) => $item + ['description' => $g['label']], $g['items']), $groups));
        $settings = array_merge(...array_map(fn (array $m) => array_filter($m['items'], fn (array $item) => $item['setting']), $this->modules));

        return [['key' => 'matches', 'label' => 'All matches', 'sub' => 'Everything that matches “'.$term.'”, from every module.', 'icon' => 'heroicon-o-magnifying-glass',
            'items' => $screens, 'sets' => $this->settingSets($settings)], ...$groups];
    }

    /**
     * @param  array<int, array{label: string, url: string, key: string}>  $settings
     * @return list<array{label: string, items: list<array{label: string, url: string}>}>
     */
    private function settingSets(array $settings): array
    {
        // Settings that all start with the same word ("performance.…") are named without it: the module says it already.
        $firsts = array_unique(array_map(fn (array $setting) => strtok($setting['key'], '.'), $settings));
        $within = count($firsts) === 1 ? reset($firsts) : null;
        $sets = [];
        foreach ($settings as $setting) {
            ['group' => $group, 'label' => $label] = ConfigurationSearch::settingParts($setting['key'], $within);
            $sets[$group][] = ['label' => $label, 'url' => $setting['url']];
        }
        // The module's own general settings lead.
        if (isset($sets['General'])) {
            $sets = ['General' => $sets['General']] + $sets;
        }

        return array_map(fn (string $label, array $items) => ['label' => $label, 'items' => $items], array_keys($sets), array_values($sets));
    }

    private function settingModule(string $key): string
    {
        $best = null;
        foreach (array_keys(self::SETTING_MODULES) as $prefix) {
            if (str_starts_with($key, $prefix.'.') && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? 'general' : self::SETTING_MODULES[$best];
    }
}
