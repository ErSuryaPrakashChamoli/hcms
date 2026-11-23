<?php

namespace App\Domain\Configuration\Cloning;

use App\Domain\Configuration\Models\ConfigurationLineage;
use Illuminate\Support\Collection;

/** Where a record came from and where it was copied to (FR-20). Lineage records only: it never links behaviour. */
final class CloneLineage
{
    /** The clone that created this record, if any (with its clone record and who made it). */
    public function origin(string $adapter, int $targetId): ?ConfigurationLineage
    {
        return ConfigurationLineage::query()->with(['operation.initiator', 'destinationCompany'])
            ->where('adapter', $adapter)->where('role', ConfigurationLineage::ROOT)->where('target_id', $targetId)->latest('id')->first();
    }

    /** @return Collection<int, ConfigurationLineage> the copies made from this record, newest first */
    public function copies(string $adapter, int $sourceId): Collection
    {
        return ConfigurationLineage::query()->with('destinationCompany')
            ->where('adapter', $adapter)->where('role', ConfigurationLineage::ROOT)->where('source_id', $sourceId)->latest('id')->get();
    }
}
