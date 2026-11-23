<?php

namespace App\Domain\Configuration\Cloning;

/**
 * What the person asked for in the clone wizard: the source, the destinations with the copies' names and codes, the
 * adapter's source options (which policy version), the unit mapping choices and the reason. The idempotency key is
 * minted when the wizard opens; the expected checksum is the source as the person reviewed it.
 *
 * Unit choices: [company id => [rule field => [source unit id => destination unit id | 'drop']]]; a unit without a
 * choice takes the suggested destination unit (same name), if there is one.
 */
final class CloneRequest
{
    public const DROP = 'drop';

    /**
     * @param  list<Destination>  $destinations
     * @param  array<string, mixed>  $options
     * @param  array<int, array<string, array<int|string, int|string|null>>>  $unitChoices
     */
    public function __construct(
        public readonly string $adapter,
        public readonly int $sourceId,
        public readonly array $destinations,
        public readonly array $options = [],
        public readonly array $unitChoices = [],
        public readonly string $reason = '',
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $expectedChecksum = null,
        public readonly bool $acknowledged = false,
    ) {}

    /** @return list<int> */
    public function companyIds(): array
    {
        return array_values(array_map(fn (Destination $d) => $d->companyId, $this->destinations));
    }

    /** @return array<string, array<int|string, int|string|null>> */
    public function unitChoicesFor(int $companyId): array
    {
        return (array) ($this->unitChoices[$companyId] ?? []);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'adapter' => $this->adapter, 'source_id' => $this->sourceId,
            'destinations' => array_map(fn (Destination $d) => $d->toArray(), $this->destinations),
            'options' => $this->options, 'unit_choices' => $this->unitChoices, 'reason' => $this->reason,
        ];
    }
}
