<?php

namespace App\Domain\Lifecycle\Exceptions;

use App\Domain\Lifecycle\Enums\LifecycleState;
use RuntimeException;

class InvalidLifecycleTransitionException extends RuntimeException
{
    public static function between(LifecycleState $from, LifecycleState $to): self
    {
        return new self(sprintf('An employee cannot move from %s to %s.', $from->getLabel(), $to->getLabel()));
    }

    public static function directMutation(object $employee): self
    {
        return new self('lifecycle_state cannot be set directly on '.$employee::class.'; use LifecycleEngine::transition().');
    }
}
