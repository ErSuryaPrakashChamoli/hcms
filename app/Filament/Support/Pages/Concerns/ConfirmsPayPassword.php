<?php

namespace App\Filament\Support\Pages\Concerns;

use App\Filament\Support\PayPasswordGate;

/**
 * HROne parity MS-9: the Livewire side of the pay-page password confirmation. The page route carries
 * ConfirmPasswordForPay (first load); this hook covers the page's later Livewire requests (a year changed, a tax slip
 * opened) once the confirmation has run out: nothing is rendered and the browser goes to the confirmation page, which
 * returns to the page afterwards. Pages shared with payroll set payPasswordScope() to "payslips" (payroll staff are
 * not asked).
 */
trait ConfirmsPayPassword
{
    public function hydrateConfirmsPayPassword(): void
    {
        if (! PayPasswordGate::required() || (static::payPasswordScope() === 'payslips' && auth()->user()?->can('payroll.view'))) {
            return;
        }
        session()->put('url.intended', $this->payPasswordReturnUrl());
        $this->skipRender();
        $this->redirect(PayPasswordGate::url());
    }

    protected static function payPasswordScope(): string
    {
        return 'self';
    }

    protected function payPasswordReturnUrl(): string
    {
        return static::getUrl();
    }
}
