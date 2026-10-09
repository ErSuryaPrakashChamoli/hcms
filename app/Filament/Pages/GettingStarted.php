<?php

namespace App\Filament\Pages;

use App\Domain\Signup\Services\TenantSetupProgress;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * SaaS.10: the first-run guide for an organisation's administrators (the Account Owner after self-service signup, or
 * any tenant administrator): five separate states (account, organisation, set-up, subscription, sensitive modules) and
 * the set-up steps, each linking to its screen when the person can open it, or naming the role it needs. Read only;
 * progressive disclosure, nothing is switched on from here.
 */
class GettingStarted extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Getting started';

    protected static ?string $title = 'Getting started';

    protected static ?string $slug = 'getting-started';

    protected static ?int $navigationSort = -20;

    protected string $view = 'filament.pages.getting-started';

    /** @var array<string, mixed>|null */
    private ?array $progress = null;

    /** Tenant administrators who set the organisation up: they invite people, give roles or change settings. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->isPlatformAdmin() && app(TenantContext::class)->has()
            && ($user->hasPermission('user.assign_roles') || $user->hasPermission('settings.update'));
    }

    /**
     * Listed (navigation, Admin centre) only in tenants registered through signup, so existing tenants' navigation is
     * unchanged; any tenant administrator can still open it by its address.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return TenantSetupProgress::fromSignup(app(TenantContext::class)->id());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'What is set up, what is left, and who can do it. You can come back here at any time.';
    }

    /** @return array<string, mixed> */
    public function progress(): array
    {
        return $this->progress ??= app(TenantSetupProgress::class)->for(auth()->user());
    }

    /**
     * The Home banner: only in a tenant registered through signup, for someone who can open this page, while steps
     * remain (so existing tenants' Home is unchanged).
     *
     * @return array{done: int, total: int, url: string}|null
     */
    public static function banner(): ?array
    {
        if (! static::canAccess() || ! TenantSetupProgress::fromSignup(app(TenantContext::class)->id())) {
            return null;
        }
        $progress = app(TenantSetupProgress::class)->for(auth()->user());

        return $progress['done'] < $progress['total'] ? ['done' => $progress['done'], 'total' => $progress['total'], 'url' => static::getUrl()] : null;
    }
}
