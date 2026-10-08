<?php

namespace App\Domain\Experience\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** UX: an anonymous, aggregate product counter (no user, no content). Not audited (derived counters). */
#[Fillable(['tenant_id', 'day', 'metric', 'count', 'total_ms'])]
class UxMetric extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'date', 'count' => 'integer', 'total_ms' => 'integer'];
    }
}
