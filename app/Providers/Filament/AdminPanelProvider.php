<?php

namespace App\Providers\Filament;

use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\RoleLens;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyCareer;
use App\Filament\Pages\MyCompensation;
use App\Filament\Pages\MyDay;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\MyLearning;
use App\Filament\Pages\Preferences;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Widgets\PeopleControlCentre;
use App\Filament\Widgets\TenantOverview;
use App\Http\Middleware\EnforceSecurityPolicy;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetAuditSource;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The PeopleOS workspace (blueprint §6, §102). Experience Transformation: Filament keeps forms, tables,
 * resources, authorisation and actions; the PeopleOS presentation layer (theme, nine-section role-adaptive
 * navigation, command center, drawers, home) sits on top through the theme and render hooks.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('PeopleOS')
            ->brandLogo(fn () => view('filament.shell.brand'))
            ->brandLogoHeight('1.75rem')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Instrument Sans')
            ->serifFont('Instrument Serif')
            ->monoFont('JetBrains Mono')
            ->databaseNotifications()
            ->colors([
                // Hand-tuned so Filament's 600 (buttons, active states) is exactly the PeopleOS iris #574bc4 and 700
                // its hover #463ba6; generated palettes are too saturated for an elegant interface.
                'primary' => [
                    50 => 'oklch(0.975 0.010 290)', 100 => 'oklch(0.955 0.019 292)', 200 => 'oklch(0.905 0.042 290)',
                    300 => 'oklch(0.825 0.080 287)', 400 => 'oklch(0.733 0.123 287)', 500 => 'oklch(0.610 0.160 284)',
                    600 => 'oklch(0.4958 0.1815 281.98)', 700 => 'oklch(0.4306 0.1651 281.57)', 800 => 'oklch(0.370 0.140 281)',
                    900 => 'oklch(0.310 0.110 280)', 950 => 'oklch(0.220 0.075 279)',
                ],
                // A cool, slightly violet gray that belongs to the PeopleOS canvas and the midnight rail.
                'gray' => [
                    50 => 'oklch(0.974 0.005 286)', 100 => 'oklch(0.955 0.007 286)', 200 => 'oklch(0.915 0.010 284)',
                    300 => 'oklch(0.855 0.014 282)', 400 => 'oklch(0.700 0.026 280)', 500 => 'oklch(0.560 0.038 279)',
                    600 => 'oklch(0.500 0.046 278)', 700 => 'oklch(0.400 0.046 278)', 800 => 'oklch(0.285 0.044 278)',
                    900 => 'oklch(0.216 0.045 278)', 950 => 'oklch(0.160 0.035 278)',
                ],
                'success' => Color::hex('#0f7a55'),
                'warning' => Color::hex('#9a5309'),
                'danger' => Color::hex('#b42335'),
                'info' => Color::hex('#2a5fbf'),
            ])
            ->spa(hasPrefetching: true)
            ->spaUrlExceptions(['*/download*', '*/attachment*', '*/attachments/*', '*/sso/*', '*/exit-tenant'])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('16rem')
            ->maxContentWidth(Width::Full)
            ->globalSearch(false)
            ->unsavedChangesAlerts()
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => auth()->user() === null
                ? $builder
                : $builder->items(app(ExperienceNavigation::class)->items(auth()->user())))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.shell.head-end'))
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('filament.shell.body-start'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn () => view('filament.shell.command-trigger'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.shell.quick-launch'))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn () => view('filament.shell.sidebar-personal'))
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn () => view('filament.shell.rail-footer'))
            ->renderHook(PanelsRenderHook::PAGE_START, fn () => view('filament.shell.space-bar'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.shell.body-end'))
            ->navigationGroups([
                NavigationGroup::make('Me'),
                NavigationGroup::make('Analytics'),
                NavigationGroup::make('People'),
                NavigationGroup::make('Organisation'),
                NavigationGroup::make('People Setup'),
                NavigationGroup::make('Access'),
                NavigationGroup::make('Customisation'),
                NavigationGroup::make('Attendance'),
                NavigationGroup::make('Leave'),
                NavigationGroup::make('Payroll'),
                NavigationGroup::make('Compliance'),
                NavigationGroup::make('Performance'),
                NavigationGroup::make('Learning'),
                NavigationGroup::make('Talent'),
                NavigationGroup::make('Workforce'),
                NavigationGroup::make('Compensation'),
                NavigationGroup::make('Assets'),
                NavigationGroup::make('Service Desk'),
                NavigationGroup::make('Grievances'),
                NavigationGroup::make('Knowledge'),
                NavigationGroup::make('Exit'),
                NavigationGroup::make('Letters'),
                NavigationGroup::make('Alumni'),
                NavigationGroup::make('Workflows'),
                NavigationGroup::make('Policies'),
                NavigationGroup::make('Engagement'),
                NavigationGroup::make('Communication'),
                NavigationGroup::make('Configuration'),
                NavigationGroup::make('Integrations'),
                NavigationGroup::make('Audit'),
                NavigationGroup::make('Enterprise'),
                NavigationGroup::make('Platform'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Home::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                PeopleControlCentre::class,
                TenantOverview::class,
            ])
            ->userMenuItems([
                // UX: personal pages live under the avatar ("You"), not in the primary navigation.
                ...collect([
                    ['my-profile', 'My profile', 'heroicon-o-identification', fn () => ($me = app(RoleLens::class)->employee(auth()->user())) && auth()->user()->can('view', $me) ? EmployeeResource::getUrl('view', ['record' => $me]) : null],
                    ['my-hr', 'My HR', 'heroicon-o-sparkles', fn () => MyHr::canAccess() ? MyHr::getUrl() : null],
                    ['my-day', 'My day', 'heroicon-o-sun', fn () => MyDay::canAccess() ? MyDay::getUrl() : null],
                    ['my-career', 'My career', 'heroicon-o-arrow-trending-up', fn () => MyCareer::canAccess() ? MyCareer::getUrl() : null],
                    ['my-learning', 'My learning', 'heroicon-o-academic-cap', fn () => MyLearning::canAccess() ? MyLearning::getUrl() : null],
                    ['my-compensation', 'My compensation', 'heroicon-o-banknotes', fn () => MyCompensation::canAccess() ? MyCompensation::getUrl() : null],
                    ['preferences', 'Preferences', 'heroicon-o-adjustments-horizontal', fn () => Preferences::canAccess() ? Preferences::getUrl() : null],
                ])->map(fn (array $i) => Action::make($i[0])->label($i[1])->icon($i[2])
                    ->visible(fn () => rescue(fn () => auth()->check() && app(TenantContext::class)->has() && $i[3]() !== null, false, false))
                    ->url(fn () => rescue($i[3], null, false)))->all(),
                Action::make('exit-tenant')
                    ->label('Exit tenant')
                    ->icon('heroicon-o-arrow-left-start-on-rectangle')
                    ->visible(fn () => auth()->user()?->isPlatformAdmin() && session()->has(ResolveTenant::SESSION_KEY))
                    ->url(fn () => route('admin.exit-tenant'))
                    ->postToUrl(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetAuditSource::class.':admin_control_centre',
            ])
            // Phase 14: persistent, so /livewire/update requests also bind the tenant and enforce the
            // tenant security policy (IP allow-list, idle timeout), not only full page loads.
            ->authMiddleware([
                Authenticate::class,
                ResolveTenant::class,
                EnforceSecurityPolicy::class,
            ], isPersistent: true)
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: fn () => app(TenantContext::class)->has() && app(SecurityPolicy::class)->mfaRequired());
    }
}
