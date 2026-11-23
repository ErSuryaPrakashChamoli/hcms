<?php

namespace App\Domain\Configuration\Cloning;

use Illuminate\Database\Eloquent\Model;

/** What an adapter created for one destination: the copy, and the rows it created with it (for lineage). */
final class Materialised
{
    /** @param  list<array{adapter: string, source_id: ?int, target_id: int}>  $children */
    public function __construct(
        public readonly Model $root,
        public readonly array $children = [],
    ) {}
}
