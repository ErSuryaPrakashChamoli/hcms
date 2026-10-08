<?php

namespace App\Support\Numbering;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 12: the last number issued for one tenant-wide sequence (e.g. TKT-2026). Derived counter rows. */
#[Fillable(['tenant_id', 'sequence', 'last_value'])]
class NumberSequence extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['last_value' => 'integer'];
    }
}
