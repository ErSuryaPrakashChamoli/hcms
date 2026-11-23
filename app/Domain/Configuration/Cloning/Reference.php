<?php

namespace App\Domain\Configuration\Cloning;

/**
 * One thing a source points at (a leave type by code, a letter template by id, a role by slug, …), classified by kind
 * with the strategy the clone applies to it. A reference the adapter cannot classify fails the plan (AC-F5).
 */
final class Reference
{
    public function __construct(
        public readonly string $path,
        public readonly string $label,
        public readonly ReferenceKind $kind,
        public readonly string $type,
        public readonly int|string $value,
        public readonly ?int $targetId,
        public readonly ?string $targetLabel,
        public readonly DependencyStrategy $strategy,
        public readonly ?string $problem = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path, 'label' => $this->label, 'kind' => $this->kind->value, 'type' => $this->type, 'value' => $this->value,
            'target_id' => $this->targetId, 'target_label' => $this->targetLabel, 'strategy' => $this->strategy->value, 'problem' => $this->problem,
        ];
    }
}
