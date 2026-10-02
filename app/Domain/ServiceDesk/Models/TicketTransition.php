<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Phase 12: one status change of a request — the case's own status history (from, to, who, via,
 * when, reason, operation). Append-only; each change is also audited on the ticket. Employees see
 * the statuses and times, never the reason of an internal step.
 */
#[Fillable(['tenant_id', 'ticket_id', 'from_status', 'to_status', 'actor_id', 'via', 'reason', 'operation_id'])]
class TicketTransition extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Request status history is append-only.'));
        static::deleting(fn () => throw new RuntimeException('Request status history is append-only.'));
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
