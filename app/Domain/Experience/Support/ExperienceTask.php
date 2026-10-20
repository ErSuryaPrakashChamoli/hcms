<?php

namespace App\Domain\Experience\Support;

use Carbon\CarbonInterface;

/**
 * Phase 12: one item on a person's unified task list. It is a reference to the owning domain's own
 * task or record, never a copy of its state: the domain decides what it is and where to act on it.
 */
final class ExperienceTask
{
    public function __construct(
        public readonly string $domain,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $reference = null,
        public readonly ?CarbonInterface $dueAt = null,
        public readonly ?string $url = null,
    ) {}

    public function isOverdue(): bool
    {
        return $this->dueAt !== null && $this->dueAt->isPast();
    }
}
