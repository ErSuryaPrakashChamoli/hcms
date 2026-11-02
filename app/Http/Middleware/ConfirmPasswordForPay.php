<?php

namespace App\Http\Middleware;

use App\Filament\Support\PayPasswordGate;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

/**
 * HROne parity MS-9: Laravel's password confirmation (RequirePassword) in front of the employee's own pay pages, only
 * while the organisation asks for it (`compensation.self_service.confirm_password`, default off = no change).
 *
 * Scope "self" (My compensation, My payslips, My salary YTD) always applies; scope "payslips" (the payslip list and
 * view, shared with payroll) applies to people who read only their own payslips — payroll staff (`payroll.view`) are
 * not asked.
 */
class ConfirmPasswordForPay extends RequirePassword
{
    /**
     * @param  Request  $request
     * @param  string|null  $redirectToRoute  the scope: "self" or "payslips"
     * @param  string|int|null  $passwordTimeoutSeconds  unused (PayPasswordGate::TIMEOUT_SECONDS)
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if (! self::applies($request, (string) ($redirectToRoute ?? 'self'))) {
            return $next($request);
        }

        return parent::handle($request, $next, PayPasswordGate::ROUTE, PayPasswordGate::TIMEOUT_SECONDS);
    }

    public static function applies(Request $request, string $scope): bool
    {
        if (! PayPasswordGate::enabled()) {
            return false;
        }

        return ! ($scope === 'payslips' && $request->user()?->can('payroll.view'));
    }
}
