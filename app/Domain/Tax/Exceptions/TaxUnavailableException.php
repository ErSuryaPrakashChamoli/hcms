<?php

namespace App\Domain\Tax\Exceptions;

use RuntimeException;

/**
 * SaaS.7: tax cannot be determined safely (no determiner for the regime, no verified rule, a treatment that is not
 * configured, missing registration data). Billing refuses to issue the invoice: no default tax is ever applied.
 */
final class TaxUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
