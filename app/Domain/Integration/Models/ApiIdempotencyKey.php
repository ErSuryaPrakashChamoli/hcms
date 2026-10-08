<?php

namespace App\Domain\Integration\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/** Phase 14: one remembered API write (Idempotency-Key). Derived replay rows; the write itself is audited by its domain. */
#[Fillable(['tenant_id', 'api_key_id', 'idempotency_key', 'method', 'path', 'request_sha256', 'status', 'response_status', 'response_body', 'expires_at'])]
#[Hidden(['response_body'])]
class ApiIdempotencyKey extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['response_body' => 'encrypted', 'response_status' => 'integer', 'expires_at' => 'datetime'];
    }
}
