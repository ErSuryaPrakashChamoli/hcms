<?php

namespace App\Domain\Configuration\Cloning;

/** One company a clone is written for, with the copy's own name and code (and, for rule-based types, its priority). */
final class Destination
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $name,
        public readonly string $code,
        public readonly ?int $priority = null,
    ) {}

    /** @return array{company_id: int, name: string, code: string, priority: ?int} */
    public function toArray(): array
    {
        return ['company_id' => $this->companyId, 'name' => $this->name, 'code' => $this->code, 'priority' => $this->priority];
    }
}
