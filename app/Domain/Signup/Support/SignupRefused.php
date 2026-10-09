<?php

namespace App\Domain\Signup\Support;

use RuntimeException;

/**
 * SaaS.10: a signup step refused for a stated reason. `code` is stable (tests, the operator page, the safe failure
 * summary); the message is safe to show the person who asked (it never names another account or tenant).
 */
final class SignupRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
