<?php

namespace App\Domain\Configuration\Cloning;

use App\Domain\Configuration\Exceptions\ConfigurationException;

/** A clone that was not written: not allowed, out of scope, blocked by a conflict, or its source changed meanwhile. */
final class CloneRefusedException extends ConfigurationException
{
    /** @param  list<Conflict>  $conflicts */
    public function __construct(string $message, public readonly array $conflicts = [])
    {
        parent::__construct($message);
    }

    /** @param  list<Conflict>  $blockers */
    public static function blocked(array $blockers): self
    {
        $first = $blockers[0]->message ?? 'Something needs fixing first.';
        $more = count($blockers) > 1 ? ' ('.(count($blockers) - 1).' more to fix.)' : '';

        return new self($first.$more, $blockers);
    }
}
