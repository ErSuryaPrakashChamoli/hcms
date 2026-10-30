<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS.8: browser security headers on every response (panel, API, downloads, health). None was set: any site could
 * frame the panel (clickjacking on approve / pay / suspend buttons), and browsers could sniff content types.
 *
 * - X-Frame-Options SAMEORIGIN and CSP frame-ancestors 'self': PeopleOS pages are never framed by another origin
 *   (PeopleOS embeds no iframes of its own). Only the frame-ancestors directive is set: a full script CSP would need
 *   Filament / Livewire nonces and is not attempted here.
 * - X-Content-Type-Options nosniff; Referrer-Policy strict-origin-when-cross-origin (no paths or tokens in referrers);
 *   Permissions-Policy turns off camera and microphone, which PeopleOS does not use, and allows geolocation for
 *   PeopleOS's own pages only (web check-in sends the location when the employee's check-in policy needs it).
 * - X-Robots-Tag noindex, nofollow (SaaS.11; peopleos.security.robots_tag), with robots.txt disallowing everything;
 * - Strict-Transport-Security only on HTTPS requests, for peopleos.security.hsts_max_age seconds (0 turns it off);
 *   subdomains are not included (that commitment is the operator's).
 * A header a route already set is kept.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $set = function (string $name, string $value) use ($headers): void {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        };
        $set('X-Frame-Options', 'SAMEORIGIN');
        $set('Content-Security-Policy', "frame-ancestors 'self'");
        $set('X-Content-Type-Options', 'nosniff');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        // SaaS.11: a private HR platform is never indexed. robots.txt asks crawlers not to visit; this header also keeps
        // out of search results a page reached through a shared link (invitation and signup links carry their token
        // in the path). Empty turns it off.
        if (($robots = (string) config('peopleos.security.robots_tag', 'noindex, nofollow')) !== '') {
            $set('X-Robots-Tag', $robots);
        }

        $maxAge = (int) config('peopleos.security.hsts_max_age', 0);
        if ($request->isSecure() && $maxAge > 0) {
            $set('Strict-Transport-Security', "max-age={$maxAge}");
        }

        return $response;
    }
}
