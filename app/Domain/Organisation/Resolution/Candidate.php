<?php

namespace App\Domain\Organisation\Resolution;

/** A record a typed value could mean: an ambiguous match, a suggestion, or a record outside the row's company. */
final class Candidate
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $code,
        public readonly ?string $company = null,
        public readonly float $score = 1.0,
        public readonly bool $live = true,
    ) {}

    public function label(): string
    {
        $label = $this->name.($this->code !== null && $this->code !== '' && $this->code !== $this->name ? " ({$this->code})" : '');
        if ($this->company !== null) {
            $label .= ", {$this->company}";
        }

        return $this->live ? $label : "{$label}, inactive";
    }

    /** @return array{id: int, name: string, code: ?string, company: ?string, score: float, live: bool} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'code' => $this->code, 'company' => $this->company, 'score' => round($this->score, 2), 'live' => $this->live];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], (string) $data['name'], $data['code'] ?? null, $data['company'] ?? null, (float) ($data['score'] ?? 1), (bool) ($data['live'] ?? true));
    }
}
