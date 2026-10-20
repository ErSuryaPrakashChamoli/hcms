<?php

namespace App\Support\Http;

use RuntimeException;

/** An outbound destination refused by the SSRF guard. The message names the rule, never an internal address. */
final class UnsafeOutboundUrl extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
