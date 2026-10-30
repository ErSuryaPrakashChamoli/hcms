<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Services\SettingFiles;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Throwable;

/**
 * HROne setup parity CA-2 (Enterprise → logo, Company → theme): the organisation's own look in the workspace, read per
 * request once the tenant is known. Without settings (the default) the workspace looks exactly as before: the PeopleOS
 * mark, the PeopleOS iris colour, and the light / dark switcher for everyone.
 */
final class TenantBranding
{
    /** A signed link to the organisation's logo, or null (no tenant, nobody signed in, or no logo). */
    public static function logoUrl(): ?string
    {
        if (auth()->user() === null || app(TenantContext::class)->id() === null) {
            return null;
        }
        try {
            return app(SettingFiles::class)->url('branding.logo_path');
        } catch (Throwable) {
            return null;
        }
    }

    /** The organisation's workspace colour (#rrggbb), or null for the PeopleOS colour. */
    public static function themeColour(): ?string
    {
        if (app(TenantContext::class)->id() === null) {
            return null;
        }
        $colour = app(SettingsRepository::class)->get('branding.theme_colour');

        return is_string($colour) && preg_match('/^#[0-9a-fA-F]{6}$/', $colour) === 1 ? strtolower($colour) : null;
    }

    /** People may switch light / dark unless the organisation turned it off; whoever changes settings always may. */
    public static function mayChangeTheme(?User $user): bool
    {
        if ($user === null || app(TenantContext::class)->id() === null) {
            return true;
        }

        return (bool) app(SettingsRepository::class)->get('branding.employee_may_change_theme', true) || $user->can('settings.update');
    }

    /**
     * The organisation's colours for Filament. Registered as a closure when the panel boots (AdminPanelProvider), so it is
     * read when the page first needs a colour: after the tenant is resolved.
     *
     * @return array<string, array<int, string>>
     */
    public static function colours(): array
    {
        return ($colour = self::themeColour()) === null ? [] : ['primary' => Color::hex($colour)];
    }

    /** Registers colours() with Filament (lazily evaluated). */
    public static function register(): void
    {
        FilamentColor::register(fn () => self::colours());
    }

    /** Rendered at the start of <head>: when the switcher is off for this person, a theme they chose earlier is dropped. */
    public static function head(): string
    {
        return self::mayChangeTheme(auth()->user()) ? '' : '<script>try { localStorage.removeItem("theme") } catch (e) {}</script>';
    }
}
