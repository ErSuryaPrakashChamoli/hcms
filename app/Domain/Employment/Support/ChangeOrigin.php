<?php

namespace App\Domain\Employment\Support;

/**
 * Phase 12: where a profile change came from — Employee 360, an approved HR service request, or the
 * API — so the domain audit, event and timeline can answer "which request caused this change". It
 * carries references only, never values.
 */
final class ChangeOrigin
{
    public function __construct(
        public readonly string $source = 'employee_360',
        public readonly ?string $reference = null,
        public readonly ?string $operationId = null,
    ) {}

    public static function employee360(): self
    {
        return new self('employee_360');
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter(['source' => $this->source, 'reference' => $this->reference, 'operation_id' => $this->operationId]);
    }
}
