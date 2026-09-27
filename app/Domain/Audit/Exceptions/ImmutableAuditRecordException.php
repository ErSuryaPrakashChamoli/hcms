<?php

namespace App\Domain\Audit\Exceptions;

use RuntimeException;

class ImmutableAuditRecordException extends RuntimeException
{
    public static function because(string $operation): self
    {
        return new self("Audit records are append-only; {$operation} is not permitted.");
    }
}
