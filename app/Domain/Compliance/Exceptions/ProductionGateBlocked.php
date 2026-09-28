<?php

namespace App\Domain\Compliance\Exceptions;

use RuntimeException;

/** The production gate refused a statutory step; carries the failed checks for screens and the API. */
final class ProductionGateBlocked extends RuntimeException
{
    /** @param  list<array{check: string, passed: bool, detail: string}>  $checks */
    public function __construct(string $message, public readonly array $checks)
    {
        parent::__construct($message);
    }
}
