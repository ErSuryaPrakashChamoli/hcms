<?php

namespace App\Domain\Platform\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS.8: a queued job that reached a worker while its tenant was suspended. The serialized job is kept encrypted with
 * the application key (it may carry a webhook URL or headers) and is dispatched again, once, when the tenant is
 * reactivated (DeferredTenantJobs). An operational queue row, not a business record: deferral is logged and the release
 * is audited with its count on the reactivation.
 */
#[Fillable(['tenant_id', 'job_class', 'payload', 'connection', 'queue', 'deferred_at'])]
#[Hidden(['payload'])]
class TenantDeferredJob extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['payload' => 'encrypted', 'deferred_at' => 'datetime'];
    }
}
