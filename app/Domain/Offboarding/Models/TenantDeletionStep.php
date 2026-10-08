<?php

namespace App\Domain\Offboarding\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** SaaS.9: one checkpointed step of a deletion (a table, its children, its files, an anonymisation, a verification, an external system). */
#[Fillable(['tenant_deletion_id', 'tenant_id', 'position', 'category', 'kind', 'target', 'status', 'planned_count', 'processed_count', 'detail', 'started_at', 'completed_at'])]
class TenantDeletionStep extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const EXTERNAL_REQUIRED = 'external_required';

    public const EXTERNAL_REQUESTED = 'external_requested';

    public const EXTERNAL_CONFIRMED = 'external_confirmed';

    public const EXTERNAL_UNSUPPORTED = 'external_unsupported';

    protected function casts(): array
    {
        return ['detail' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'planned_count' => 'integer', 'processed_count' => 'integer', 'position' => 'integer'];
    }
}
