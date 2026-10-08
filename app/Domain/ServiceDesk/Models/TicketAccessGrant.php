<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 12: explicit access to a restricted (confidential / employee-relations) case — the "explicit
 * scope" step of Tenant → Permission → Classification → Explicit scope → Record. Granted with a
 * reason, revoked (never deleted), audited.
 */
#[Fillable(['tenant_id', 'ticket_id', 'user_id', 'granted_by', 'reason', 'revoked_at', 'revoked_by'])]
class TicketAccessGrant extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \RuntimeException('Case access grants are revoked, never deleted.'));
    }

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'servicedesk';
    }

    public function auditLabel(): string
    {
        return 'Case access #'.$this->ticket_id.' → user #'.$this->user_id;
    }

    /** The reason for access to a confidential case can describe the case: masked in the field diff. */
    public function auditSensitiveAttributes(): array
    {
        return ['reason'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
