<?php

namespace App\Domain\Experience\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity RQ-8 (Inbox → Insight): a decision a person took on an Approval Center item that is a domain request, with
 * the due time the item showed when it was decided. Written only by ApprovalDecisions after the owning service decided
 * (which audits the decision itself); derived and append-only, so it is not audited again. Read by InboxInsight.
 */
#[Fillable(['tenant_id', 'user_id', 'on_behalf_of_user_id', 'item_id', 'item_type', 'decision', 'due_at', 'decided_at'])]
class InboxDecision extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
