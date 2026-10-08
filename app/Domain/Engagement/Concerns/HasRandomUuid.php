<?php

namespace App\Domain\Engagement\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * Phase 13: random (v4) UUID primary keys. Laravel's default UUIDs are time-ordered, so their order
 * would reveal submission order. For anonymous content, row order must reveal nothing.
 */
trait HasRandomUuid
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
