<?php

namespace App\Domain\Identity\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS.2: an invitation for one invited user of one tenant. Only the SHA-256 of the token is stored; the
 * token itself exists only in the e-mailed link. Issued, accepted and revoked events are audited
 * explicitly by UserInvitations (automatic auditing would copy the token hash into the trail).
 */
#[Fillable(['tenant_id', 'user_id', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'revoked_at', 'revoked_reason'])]
#[Hidden(['token_hash'])]
class UserInvitation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->gt(now());
    }
}
