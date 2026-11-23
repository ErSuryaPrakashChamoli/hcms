<?php

namespace App\Domain\Configuration\Cloning;

use App\Domain\Configuration\Models\ConfigurationCloneOperation;
use App\Domain\Configuration\Models\ConfigurationLineage;
use Illuminate\Database\Eloquent\Model;

/** A clone that was made (or was already made by the same wizard: repeated), with the copies it created. */
final class CloneResult
{
    /** @param  list<Model>  $copies */
    public function __construct(
        public readonly ConfigurationCloneOperation $operation,
        public readonly array $copies,
        public readonly bool $repeated = false,
    ) {}

    public static function fromOperation(ConfigurationCloneOperation $operation, CloneAdapter $adapter): self
    {
        $copies = $operation->lineage()->where('role', ConfigurationLineage::ROOT)->orderBy('id')->pluck('target_id')
            ->map(fn ($id) => $adapter->find((int) $id))->filter()->values()->all();

        return new self($operation, $copies, true);
    }

    public function completed(): bool
    {
        return $this->operation->status === ConfigurationCloneOperation::COMPLETED;
    }
}
