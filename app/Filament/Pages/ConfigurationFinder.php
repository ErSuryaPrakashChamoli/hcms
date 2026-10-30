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
 * "What do you want to configure?" (§103): every configuration screen and setting the viewer may open, as module
 * cards, narrowed as they type. Each screen is listed only if the module catalogue says the viewer can open it.
 */
class ConfigurationFinder extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'What do you want to configure?';

    protected static ?string $title = 'What do you want to configure?';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.configuration-finder';

    /** The module cards, in order: key → [label, what it covers, icon]. Each configuration map entry names its card. */
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

    /** Tenant setting key prefix → card; the longest matching prefix wins, anything else is company-wide. */
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
        return 'Everything you can set up, by module. Type to narrow it down, or open any card to see all of it.';
    }

    /**
     * The cards with what the viewer may open in each: configuration screens first, then individual settings
     * (names and keys only, never values). While searching, only matching items and their cards remain.
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
                $items[$this->settingModule($key)][] = ['label' => ConfigurationSearch::settingLabel($key), 'url' => $settings.'?search='.urlencode($key), 'setting' => true];
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
