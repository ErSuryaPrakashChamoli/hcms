<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/** SaaS.7: a webhook failed verification (signature, stale timestamp, malformed body). Answered 401; nothing is stored. */
final class WebhookRejectedException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct("Webhook rejected: {$reasonCode}.");
    }
}
