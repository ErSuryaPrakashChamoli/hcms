<?php

namespace App\Support\Commercial;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SaaS.7: the guard every commercial and financial mutation runs in its service (never only on a page): a platform
 * operator (flag and no tenant; MFA is enforced per request by SaaS.2) and a reason of 5 to 500 characters.
 */
final class OperatorChange
{
    public static function assert(?User $actor, string $reason, string $what = 'commercial billing'): User
    {
        if ($actor === null || ! $actor->isPlatformAdmin()) {
            throw new RuntimeException("Only platform operators can change {$what}.");
        }
        $length = Str::length(trim($reason));
        if ($length < 5) {
            throw new RuntimeException('A reason is required.');
        }
        if ($length > 500) {
            throw new RuntimeException('The reason is limited to 500 characters.');
        }

        return $actor;
    }
}
