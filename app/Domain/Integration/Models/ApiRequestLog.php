<?php

namespace App\Domain\Integration\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity CA-11: one API call made with a verified key (method, path with ids, route name, status, duration, IP, user
 * agent). Never a body, a header or a query string. Append-only; purged after retention.api_request_logs_days.
 */
#[Fillable(['tenant_id', 'api_key_id', 'method', 'path', 'route_name', 'status', 'duration_ms', 'ip', 'user_agent', 'created_at'])]
class ApiRequestLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['status' => 'integer', 'duration_ms' => 'integer', 'created_at' => 'datetime'];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
