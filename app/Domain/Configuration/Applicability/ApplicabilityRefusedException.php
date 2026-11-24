<?php

namespace App\Domain\Configuration\Applicability;

use RuntimeException;

/** An applicability change or override that the person may not make, or that would break the configuration. */
final class ApplicabilityRefusedException extends RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }

    /** @param  list<string>  $problems */
    public static function because(string $lead, array $problems): self
    {
        return new self($lead.' '.implode(' ', $problems), $problems);
    }
}
