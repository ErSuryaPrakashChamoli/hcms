<?php

namespace App\Domain\Website\Support;

/**
 * Links operators type into the website editor. Only site paths ("/pricing", "/product/payroll", "#faq"), web
 * addresses (http, https), mail and phone links are allowed; anything else (javascript:, data:, protocol-relative
 * "//host") renders as "#" so a public page can never carry a script link.
 */
final class SiteLink
{
    public static function isAllowed(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '//')) {
            return false;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return ! preg_match('/[\s<>"\'`\\\\]/', $url);
        }

        return (bool) preg_match('~^(https?://[^\s<>"\'`]+|mailto:[^\s<>"\'`]+|tel:\+?[0-9 ()-]{3,})$~i', $url);
    }

    /** The URL to put in an href: a safe link as typed, otherwise "#". */
    public static function href(?string $url): string
    {
        return self::isAllowed($url) ? trim((string) $url) : '#';
    }

    public static function isExternal(?string $url): bool
    {
        $url = trim((string) $url);
        if (! preg_match('~^https?://~i', $url)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, (string) parse_url((string) config('app.url'), PHP_URL_HOST)) !== 0;
    }

    /** A validation rule closure for Filament fields. */
    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (filled($value) && ! self::isAllowed((string) $value)) {
                $fail('Use a site path such as /pricing, a full https:// address, mailto: or tel:.');
            }
        };
    }
}
