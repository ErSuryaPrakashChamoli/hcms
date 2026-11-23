<?php

namespace App\Domain\Configuration\Cloning;

/**
 * A source's content as a clone reads it: deterministic JSON whose SHA-256 is its version stamp (most configuration has no
 * version number, so the checksum is what "the source as it was copied" means). Identity (name, code, …), content
 * (settings, items, lines, …) and applicability (in the shape of the adapter's ApplicabilityKind) are kept apart, so a
 * clone can replace identity and applicability and copy content unchanged.
 */
final class Snapshot
{
    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $applicability
     */
    public function __construct(
        public readonly string $adapter,
        public readonly int $sourceId,
        public readonly string $label,
        public readonly array $identity,
        public readonly array $content,
        public readonly array $applicability,
    ) {}

    /** @return array{adapter: string, source_id: int, label: string, identity: array<string, mixed>, content: array<string, mixed>, applicability: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'adapter' => $this->adapter, 'source_id' => $this->sourceId, 'label' => $this->label,
            'identity' => $this->identity, 'content' => $this->content, 'applicability' => $this->applicability,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['adapter'], (int) $data['source_id'], (string) $data['label'], (array) ($data['identity'] ?? []),
            (array) ($data['content'] ?? []), (array) ($data['applicability'] ?? []));
    }

    public function checksum(): string
    {
        return hash('sha256', (string) json_encode(self::canonical($this->toArray()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Keys sorted at every level (lists keep their order), so the same content always hashes the same. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
