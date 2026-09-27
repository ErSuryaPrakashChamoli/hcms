<?php

namespace App\Providers\Filament;

use App\Domain\Enterprise\Services\SecurityPolicy;
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
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The Admin Control Centre (blueprint §6, §102).
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
            ->brandName('PeopleOS · Admin Control Centre')
            ->databaseNotifications()
            ->colors([
                'primary' => Color::Amber,
            ])
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
                NavigationGroup::make('Performance'),
                NavigationGroup::make('Learning'),
                NavigationGroup::make('Assets'),
                NavigationGroup::make('Service Desk'),
                NavigationGroup::make('Grievances'),
                NavigationGroup::make('Knowledge'),
                NavigationGroup::make('Exit'),
                NavigationGroup::make('Letters'),
                NavigationGroup::make('Alumni'),
                NavigationGroup::make('Workflows'),
                NavigationGroup::make('Policies'),
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
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                PeopleControlCentre::class,
                TenantOverview::class,
            ])
            ->userMenuItems([
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
            ->authMiddleware([
                Authenticate::class,
                ResolveTenant::class,
                EnforceSecurityPolicy::class,
            ])
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: fn () => app(TenantContext::class)->has() && app(SecurityPolicy::class)->mfaRequired());
    }
}
