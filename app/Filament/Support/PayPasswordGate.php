<?php

namespace App\Filament\Support;

use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;

/**
 * HROne parity MS-9 (owner decision PRV-4 / D-MS10 open): "Enter Password to open CTC / Payslip / Salary YTD". With the
 * setting `compensation.self_service.confirm_password` on (default off = the pages open directly, as before) the
 * employee's own pay pages — My compensation, My payslips, My salary YTD and their own payslips — ask for the login
 * password again, Laravel's password-confirmation pattern: the confirmation time is kept in the session
 * (`auth.password_confirmed_at`, set by `Session::passwordConfirmed()`) and lasts TIMEOUT_SECONDS.
 */
final class PayPasswordGate
{
    /** How long a confirmation lasts (HROne asks on every visit; fifteen minutes keeps a working session usable). */
    public const TIMEOUT_SECONDS = 900;

    /** The confirmation page (Filament panel route; see AdminPanelProvider::authenticatedRoutes). */
    public const ROUTE = 'filament.admin.auth.confirm-pay-password';

    public static function enabled(): bool
    {
        return app(TenantContext::class)->has() && (bool) app(SettingsRepository::class)->get('compensation.self_service.confirm_password', false);
    }

    /** Whether the password was confirmed in this session within the timeout. */
    public static function confirmed(): bool
    {
        $at = (int) session()->get('auth.password_confirmed_at', 0);

        return $at > 0 && Date::now()->unix() - $at <= self::TIMEOUT_SECONDS;
    }

    /** The setting is on and the session has no recent confirmation: pay figures wait for the password. */
    public static function required(): bool
    {
        return self::enabled() && ! self::confirmed();
    }

    public static function url(): string
    {
        return route(self::ROUTE);
    }
}
