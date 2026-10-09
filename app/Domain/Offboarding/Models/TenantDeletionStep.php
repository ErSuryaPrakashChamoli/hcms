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

    /** The external party's deletion request failed or was refused (record the reason; request again). */
    public const EXTERNAL_FAILED = 'external_failed';

    /** The copy must be kept for now (a backup's own retention, a legal requirement): record until when. */
    public const EXTERNAL_RETENTION_REQUIRED = 'external_retention_required';

    /** What an operator may record for an external copy (CONFIRMED is final). */
    public const EXTERNAL_RECORDABLE = [self::EXTERNAL_REQUESTED, self::EXTERNAL_CONFIRMED, self::EXTERNAL_FAILED, self::EXTERNAL_UNSUPPORTED, self::EXTERNAL_RETENTION_REQUIRED];

    /** @var array<string, string> external statuses as shown on the page and in the certificate */
    public const EXTERNAL_LABELS = [
        self::EXTERNAL_REQUIRED => 'PENDING', self::EXTERNAL_REQUESTED => 'REQUESTED', self::EXTERNAL_CONFIRMED => 'CONFIRMED',
        self::EXTERNAL_FAILED => 'FAILED', self::EXTERNAL_UNSUPPORTED => 'UNSUPPORTED', self::EXTERNAL_RETENTION_REQUIRED => 'RETENTION_REQUIRED',
    ];

    protected function casts(): array
    {
        return ['detail' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'planned_count' => 'integer', 'processed_count' => 'integer', 'position' => 'integer'];
    }
}
