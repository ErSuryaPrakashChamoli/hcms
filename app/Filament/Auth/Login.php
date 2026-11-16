<?php

namespace App\Filament\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MultiFactor;
use App\Domain\Identity\Services\RememberedWorkspace;
use App\Filament\Support\SignInBranding;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;

/**
 * SaaS.2: Filament's password sign-in, which challenges an authenticator before signing in. The proof is
 * remembered for this session only; every other way into a session (remember-me cookie, SSO) is challenged
 * again by EnforceAccountSecurity.
 *
 * Branded sign-in (HROne-style): a split page, the form on the right. A browser that remembers an organisation (someone
 * signed in there before, or opened the organisation's sign-in link) sees that organisation's picture or logo on the
 * left and its logo and name over the form; any other browser sees the PeopleOS page. See SignInBranding.
 */
class Login extends BaseLogin
{
    protected string $view = 'filament.auth.login';

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();
        $user = Filament::auth()->user();

        // A response is only returned after the challenge was passed, for a user who has an authenticator.
        if ($response !== null && $user instanceof User && $user->hasMfaEnabled()) {
            app(MultiFactor::class)->markVerified(session()->driver(), $user);
        }

        return $response;
    }

    /** @return array{name: string, logo: ?string, picture: ?string, colour: ?string}|null */
    public function branding(): ?array
    {
        return SignInBranding::current();
    }

    /** "Not your organisation?": this browser forgets the organisation and shows the PeopleOS sign-in page. */
    public function forgetWorkspace(): void
    {
        app(RememberedWorkspace::class)->forget();
        $this->redirect(Filament::getLoginUrl());
    }

    /** The split page fills the window (a class on the layout's main element, styled in theme.css §Q). */
    public function getMaxContentWidth(): Width|string|null
    {
        return 'pos-signin-main';
    }

    /** A branded page shows the organisation's logo over the form instead of the PeopleOS mark. */
    public function hasLogo(): bool
    {
        return $this->branding() === null;
    }

    public function getHeading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        return ($brand = $this->branding()) === null ? 'Sign in to PeopleOS' : 'Sign in to '.$brand['name'];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return parent::getSubheading() ?? (filled($this->userUndertakingMultiFactorAuthentication) ? null : 'Use your work e-mail address and password.');
    }
}
