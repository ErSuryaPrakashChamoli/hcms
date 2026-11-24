<?php

namespace App\Support\Masters;

use RuntimeException;

/** A master record something still uses: the message names the counts and what to do instead. */
final class MasterInUseException extends RuntimeException
{
    /** @param  list<array{table: string, column: string, count: int, label: string}>  $uses */
    public function __construct(string $message, public readonly array $uses = [])
    {
        parent::__construct($message);
    }
}
