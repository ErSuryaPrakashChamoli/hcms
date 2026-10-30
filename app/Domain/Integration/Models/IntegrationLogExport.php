<?php

namespace App\Domain\Integration\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity SV-26: a queued CSV export of the webhook delivery log or of an API key's call log. Audited explicitly by
 * IntegrationLogExports (EXPORT when asked for, DOWNLOAD when fetched); only the person who asked downloads it.
 */
#[Fillable(['tenant_id', 'requested_by', 'kind', 'filters', 'status', 'row_count', 'disk', 'path', 'error', 'started_at', 'finished_at'])]
class IntegrationLogExport extends Model
{
    use BelongsToTenant;

    public const KINDS = ['webhook_deliveries' => 'Webhook deliveries', 'api_request_logs' => 'API calls'];

    public const STATUSES = ['queued' => 'Queued', 'running' => 'Running', 'completed' => 'Ready', 'failed' => 'Failed'];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'row_count' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function hasFile(): bool
    {
        return $this->status === 'completed' && filled($this->getAttributes()['path'] ?? null);
    }
}
