<?php

namespace App\Domain\Employment\Requirements;

use InvalidArgumentException;

/**
 * A position change refused by a Block rule of the employment data requirements. An InvalidArgumentException, like the
 * other refusals of a position change, so the API answers 422, an upload row shows the message and a screen notifies.
 */
final class EmploymentRequirementsException extends InvalidArgumentException
{
    public function __construct(public readonly RequirementsResult $result)
    {
        parent::__construct(implode(' ', $result->blockingMessages()));
    }
}
