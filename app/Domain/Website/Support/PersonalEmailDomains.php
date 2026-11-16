<?php

namespace App\Domain\Website\Support;

/**
 * Free (personal) and throwaway e-mail providers, refused by the website's contact form when it asks for a work e-mail
 * (the contact block's "Work e-mail only" setting, on by default). Operators can block more domains on the block.
 * A family such as yahoo.* matches every country domain (yahoo.co.in, yahoo.com, …).
 */
final class PersonalEmailDomains
{
    /** @var list<string> exact domains */
    public const DOMAINS = [
        'gmail.com', 'googlemail.com', 'icloud.com', 'me.com', 'mac.com', 'msn.com', 'passport.com',
        'rediffmail.com', 'rediff.com', 'proton.me', 'protonmail.com', 'pm.me', 'tutanota.com', 'tuta.io',
        'zohomail.com', 'zohomail.in', 'mail.com', 'email.com', 'inbox.com', 'fastmail.com', 'hey.com',
        'mail.ru', 'qq.com', '163.com', '126.com', 'naver.com', 'web.de', 'libero.it', 'orange.fr', 'free.fr',
        // throwaway inboxes
        'mailinator.com', 'guerrillamail.com', 'sharklasers.com', 'yopmail.com', '10minutemail.com', 'temp-mail.org',
        'tempmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'mailnesia.com', 'throwawaymail.com',
    ];

    /** @var list<string> provider families: the name before the country or generic domain */
    public const FAMILIES = ['yahoo', 'ymail', 'rocketmail', 'hotmail', 'outlook', 'live', 'windowslive', 'aol', 'gmx', 'yandex'];

    /** @param list<string> $extra operator-blocked domains */
    public static function isPersonal(string $email, array $extra = []): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = rtrim(mb_strtolower(trim(substr($email, $at + 1))), '.');
        if ($domain === '') {
            return false;
        }
        if (in_array($domain, self::DOMAINS, true) || in_array($domain, $extra, true)) {
            return true;
        }
        $first = explode('.', $domain)[0];

        return in_array($first, self::FAMILIES, true) && substr_count($domain, '.') <= 2;
    }

    /**
     * Operator-entered domains ("acme-mail.com, other.in" or one a line), cleaned to bare lower-case domains.
     *
     * @return list<string>
     */
    public static function parse(?string $list): array
    {
        $parts = preg_split('/[\s,;]+/', mb_strtolower((string) $list)) ?: [];

        return array_values(array_unique(array_filter(array_map(fn (string $d) => ltrim(trim($d), '@.'), $parts),
            fn (string $d) => (bool) preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d))));
    }

    /** Whether a contact block asks for a work e-mail only (on unless the operator turned it off). */
    public static function workOnly(?array $contact): bool
    {
        return (bool) ($contact['work_email_only'] ?? true);
    }

    public const MESSAGE = 'Please use your work e-mail address. Personal addresses such as Gmail, Yahoo or Outlook are not accepted.';
}
