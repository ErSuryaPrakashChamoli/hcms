<?php

namespace App\Domain\Identity\Services;

use App\Domain\Platform\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;

/**
 * Branded sign-in (HROne-style): the organisation this browser last signed in to. Every sign-in of an organisation's
 * person (password, remember-me, SSO; RememberSignInWorkspace) leaves a cookie naming the organisation, and the sign-in
 * page then shows that organisation's look (SignInBranding). An organisation's sign-in link does the same before anyone
 * has signed in on the device.
 *
 * The cookie holds only the organisation's id, encrypted and authenticated with the app key by EncryptCookies (a value
 * that does not decrypt is ignored), so it cannot be edited to show another organisation. It grants nothing: it only
 * picks the look of the sign-in page and its two public images. Signing out keeps it (the next sign-in is the point);
 * "Not your organisation?" on the sign-in page forgets it.
 */
final class RememberedWorkspace
{
    public const COOKIE = 'peopleos_workspace';

    /** A year after the last sign-in (browsers cap a cookie's life at about 400 days). */
    public const MINUTES = 60 * 24 * 365;

    public function remember(Tenant $tenant): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, (string) $tenant->getKey(), self::MINUTES));
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /** The organisation this browser remembers, while it is open (trial or active); otherwise null. */
    public function tenant(?Request $request = null): ?Tenant
    {
        $value = ($request ?? request())->cookie(self::COOKIE);
        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1) {
            return null;
        }
        $tenant = Tenant::query()->find((int) $value);

        return $tenant?->isAccessible() ? $tenant : null;
    }

    /**
     * The organisation's sign-in link: opening it remembers the organisation on that browser and shows the sign-in page.
     * Signed, so only links PeopleOS gave out work (nobody can try organisation names); it signs nobody in.
     */
    public static function signInLink(Tenant $tenant): string
    {
        return URL::signedRoute('sign-in.link', ['workspace' => $tenant->slug]);
    }
}
