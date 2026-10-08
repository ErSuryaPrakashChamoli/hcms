<?php

namespace App\Domain\Offboarding\Models;

use App\Domain\Offboarding\Enums\LegalHoldStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** SaaS.9: a legal hold on a tenant's data (LegalHolds). While it holds, nothing it covers is deleted or purged. */
#[Fillable(['tenant_id', 'reference', 'status', 'scope', 'categories', 'reason', 'matter_reference', 'starts_on', 'placed_by', 'placed_at',
    'release_requested_by', 'release_requested_at', 'release_reason', 'released_by', 'released_at'])]
class LegalHold extends Model
{
    use BelongsToTenant;

    public const SCOPE_TENANT = 'tenant';

    public const SCOPE_CATEGORIES = 'categories';

    protected function casts(): array
    {
        return ['status' => LegalHoldStatus::class, 'categories' => 'array', 'starts_on' => 'date', 'placed_at' => 'datetime',
            'release_requested_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function covers(string $category): bool
    {
        return $this->status->holding() && ($this->scope === self::SCOPE_TENANT || in_array($category, (array) $this->categories, true));
    }
}
