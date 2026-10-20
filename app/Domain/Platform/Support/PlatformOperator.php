<?php

namespace App\Domain\Platform\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SaaS.8: the guard every tenant lifecycle operation runs in its service, never only on a page (the Tenants resource is
 * platform-only, but a service can be reached from anywhere). A named actor must be a platform operator (flag and no
 * tenant; MFA is enforced per request by SaaS.2). Without one, only the console may act (an operator's shell: seeders,
 * runbook commands); a web request that names no operator is refused, whoever is signed in.
 */
final class PlatformOperator
{
    public static function assert(?User $actor, string $what): ?User
    {
        if ($actor === null ? ! app()->runningInConsole() : ! $actor->isPlatformAdmin()) {
            throw new RuntimeException("Only platform operators can {$what}.");
        }

        return $actor;
    }

    /** A reason of 5 to 1,000 characters (kept on both audit chains). */
    public static function reason(?string $reason): string
    {
        $reason = trim((string) $reason);
        $length = Str::length($reason);
        if ($length < 5) {
            throw new RuntimeException('A reason is required.');
        }
        if ($length > 1000) {
            throw new RuntimeException('The reason is limited to 1,000 characters.');
        }

        return $reason;
    }
}
