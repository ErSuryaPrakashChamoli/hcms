<?php

namespace App\Filament\Pages;

use App\Domain\Ai\Services\ConfigurationSearch;
use App\Domain\Configuration\Enums\ChangeStatus;
use App\Domain\Configuration\Models\ConfigurationChange;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Integration\Models\InboundEvent;
use App\Filament\Resources\ConfigurationChanges\ConfigurationChangeResource;
use App\Filament\Resources\InboundEvents\InboundEventResource;
use App\Filament\Resources\TenantSettings\TenantSettingResource;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /** UX.15: plain-language categories of what an administrator manages (Filament groups → category). */
    public const CATEGORIES = [
        'organisation' => ['Organisation and positions', 'Companies, departments, locations, positions and assets.', 'heroicon-o-building-office-2', ['Organisation', 'Workforce', 'Assets']],
        'people' => ['People data and forms', 'Designations, grades, categories, document types, custom fields and forms.', 'heroicon-o-identification', ['People Setup', 'Customisation']],
        'lifecycle' => ['Joining, letters and exit', 'Onboarding templates, letter templates, exit and alumni settings.', 'heroicon-o-arrow-path', ['People', 'Exit', 'Letters', 'Alumni']],
        'time' => ['Time and leave rules', 'Leave types, holidays, shifts, schedules and attendance devices.', 'heroicon-o-calendar-days', ['Attendance', 'Leave']],
        'pay' => ['Pay, compensation and compliance', 'Salary components and structures, pay ranges, statutory rules and returns.', 'heroicon-o-banknotes', ['Payroll', 'Compensation', 'Compliance']],
        'growth' => ['Growth programmes', 'Performance cycles and templates, learning, skills and talent.', 'heroicon-o-arrow-trending-up', ['Performance', 'Learning', 'Talent']],
        'workflows' => ['Workflows and policies', 'Approval workflows and the policies employees acknowledge.', 'heroicon-o-arrows-right-left', ['Workflows', 'Policies']],
        'service' => ['Service and communication', 'The HR service catalogue, knowledge, grievances, announcements and surveys.', 'heroicon-o-lifebuoy', ['Service Desk', 'Knowledge', 'Grievances', 'Communication', 'Engagement']],
        'access' => ['Access and security', 'Users, roles, single sign-on, security policy and API keys.', 'heroicon-o-key', ['Access', 'Enterprise']],
        'data' => ['Integrations, data and audit', 'Integration hub, inbound events, reports, audit and configuration packs.', 'heroicon-o-circle-stack', ['Integrations', 'Analytics', 'Audit', 'Configuration', 'Platform']],
    ];

    /** Find a setting: plain words in, the configuration or module to open out. */
    public string $find = '';

    public function getHeading(): string
    {
        return 'What do you want to manage?';
    }

    public function getSubheading(): ?string
    {
        $g = $this->governance;
        $parts = array_filter([
            $g['pending'] !== null && $g['pending']->count() > 0 ? $g['pending']->count().' configuration '.($g['pending']->count() === 1 ? 'change awaits' : 'changes await').' approval' : null,
            ($g['dead_letters'] ?? 0) > 0 ? $g['dead_letters'].' integration '.($g['dead_letters'] === 1 ? 'message needs' : 'messages need').' attention' : null,
            ($g['failed_jobs'] ?? 0) > 0 ? $g['failed_jobs'].' background '.($g['failed_jobs'] === 1 ? 'job has' : 'jobs have').' failed' : null,
        ]);

        return $parts === [] ? 'Find a setting in plain words, or choose an area below. Nothing needs your attention right now.' : ucfirst(implode(' · ', $parts)).'.';
    }

    /** Words too general to pick out a setting on their own ("probation period" is about probation). */
    private const GENERIC_WORDS = ['period', 'setting', 'settings', 'policy', 'days', 'time', 'change', 'rule', 'rules'];

    /**
     * Best matches for the search box: the configuration map (§103) and module names, limited to what the
     * viewer can open (the module catalogue already applies each destination's canAccess()).
     *
     * @return list<array{label: string, url: string, hint: string}>
     */
    #[Computed]
    public function matches(): array
    {
        $term = trim($this->find);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $all = collect(array_merge(...array_map(fn (string $k) => app(ExperienceNavigation::class)->modulesIn(auth()->user(), $k), array_keys(ExperienceNavigation::SECTIONS))));
        // Only a module's own path (or a path below it) counts; the panel root (Home) never covers other screens.
        $root = rtrim((string) parse_url(Filament::getPanel('admin')->getUrl(), PHP_URL_PATH), '/');
        $paths = $all->map(fn (array $m) => rtrim((string) parse_url($m['url'], PHP_URL_PATH), '/'))->filter(fn ($p) => $p !== '' && $p !== $root)->unique()->all();
        $out = [];
        // UX.15.23: individual settings by name, first (the finder's own example "probation period" has to land on the
        // setting). Names and keys only, never values; only for those who may open the settings screen.
        $settings = rescue(fn () => TenantSettingResource::canAccess() ? TenantSettingResource::getUrl('index') : null, null, false);
        $words = array_diff(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($term)), fn ($w) => mb_strlen($w) >= 4), self::GENERIC_WORDS);
        if ($settings !== null && $words !== []) {
            foreach (array_keys(config('peopleos.settings', [])) as $key) {
                $plain = str_replace(['.', '_'], ' ', $key);
                if (collect($words)->contains(fn (string $w) => str_contains($plain, $w))) {
                    $out[$settings.'?search='.urlencode($key)] = ['label' => ucfirst(implode(' · ', array_map(fn ($s) => str_replace('_', ' ', $s), explode('.', $key)))),
                        'url' => $settings.'?search='.urlencode($key), 'hint' => 'Setting · '.$key];
                }
            }
        }
        $found = app(ConfigurationSearch::class)->search($term, 8);
        // A four-letter prefix alone (score 1: "peri" inside "experience") only counts when nothing matches better.
        $best = max(array_column($found, 'score') ?: [0]);
        foreach (array_filter($found, fn (array $r) => $r['score'] > 1 || $best <= 1) as $r) {
            $path = rtrim((string) parse_url($r['url'], PHP_URL_PATH), '/');
            $allowed = $path !== '' && collect($paths)->contains(fn ($p) => $path === $p || str_starts_with($path, $p.'/'));
            if ($allowed) {
                $out[$r['url']] = ['label' => $r['label'], 'url' => $r['url'], 'hint' => 'Setting'];
            }
        }
        $needle = mb_strtolower($term);
        foreach ($all as $m) {
            if (str_contains(mb_strtolower($m['label'].' '.$m['group']), $needle) && ! isset($out[$m['url']])) {
                $out[$m['url']] = ['label' => $m['label'], 'url' => $m['url'], 'hint' => $m['group']];
            }
        }

        return array_slice(array_values($out), 0, 10);
    }

    /**
     * Governance at a glance. Each figure appears only for viewers who may open the screen it summarises.
     *
     * @return array{pending: ?Collection, recent: ?Collection, dead_letters: ?int, failed_jobs: ?int, links: array<string, string>}
     */
    #[Computed]
    public function governance(): array
    {
        $canConfig = rescue(fn () => ConfigurationChangeResource::canAccess(), false, false);
        $canIntegrations = rescue(fn () => InboundEventResource::canAccess(), false, false);
        $canReadiness = rescue(fn () => PlatformReadinessPage::canAccess(), false, false);

        return [
            'pending' => $canConfig ? ConfigurationChange::query()->where('status', ChangeStatus::PendingApproval)->latest('id')->limit(5)->get() : null,
            'recent' => $canConfig ? ConfigurationChange::query()->where('status', ChangeStatus::Published)->latest('published_at')->limit(3)->get() : null,
            'dead_letters' => $canIntegrations ? InboundEvent::query()->where('status', 'dead_letter')->count() + WebhookDelivery::query()->where('status', 'dead_letter')->count() : null,
            'failed_jobs' => $canReadiness && Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            'links' => array_filter([
                'config' => $canConfig ? ConfigurationChangeResource::getUrl('index') : null,
                'integrations' => $canIntegrations ? InboundEventResource::getUrl('index') : null,
                'readiness' => $canReadiness ? PlatformReadinessPage::getUrl() : null,
            ]),
        ];
    }

    /** @return list<array{key: string, label: string, why: string, icon: string, modules: list<array<string, mixed>>}> */
    #[Computed]
    public function categories(): array
    {
        $nav = app(ExperienceNavigation::class);
        $byGroup = [];
        foreach (array_keys(ExperienceNavigation::SECTIONS) as $key) {
            foreach ($nav->modulesIn(auth()->user(), $key) as $m) {
                $byGroup[(string) $m['group']][$m['key']] = $m;
            }
        }
        $out = [];
        foreach (self::CATEGORIES as $key => [$label, $why, $icon, $groups]) {
            $modules = array_values(array_merge(...array_map(fn ($g) => array_values($byGroup[$g] ?? []), $groups)));
            if ($modules !== []) {
                $out[] = compact('key', 'label', 'why', 'icon', 'modules');
            }
        }

        return $out;
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
