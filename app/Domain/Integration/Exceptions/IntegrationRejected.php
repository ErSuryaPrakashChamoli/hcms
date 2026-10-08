<?php

namespace App\Domain\Integration\Exceptions;

use RuntimeException;

/**
 * Phase 14: a permanent refusal: bad signature, unknown system, invalid payload, or a handler that
 * cannot apply the event. It is never retried; a transient failure is any other exception.
 */
class IntegrationRejected extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'rejected', public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
