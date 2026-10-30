<?php

namespace App\Domain\Employment\Support;

/**
 * Phase 12: where a profile change came from — Employee 360, an approved HR service request, or the
 * API — so the domain audit, event and timeline can answer "which request caused this change". It
 * carries references only, never values. HROne parity MS-3: `self_service` = a profile change the
 * employee asked for on My profile (reference "PCR-…").
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

    /** HROne parity MS-3: a self-service profile change request (ProfileChanges). */
    public static function selfService(string $reference, ?string $operationId = null): self
    {
        return new self('self_service', $reference, $operationId);
    }

    public function isSelfService(): bool
    {
        return $this->source === 'self_service';
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter(['source' => $this->source, 'reference' => $this->reference, 'operation_id' => $this->operationId]);
    }
}
