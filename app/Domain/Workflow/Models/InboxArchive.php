<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity P4 (Inbox → Move to archive, Archive inbox): one Approval Center item a person archived (or the
 * nightly auto-archive archived for them). Archiving only hides the item from that person's "Waiting for me" and the
 * counts: it never decides, cancels or pauses the request, its SLA or its escalation. Restoring brings it back (and the
 * auto-archive leaves a restored item alone). Keyed by the item id and type (a leave request and its cancellation share
 * an id). Written only by InboxArchives; audited.
 */
#[Fillable(['tenant_id', 'user_id', 'item_id', 'item_type', 'title', 'archived_at', 'archived_by', 'restored_at', 'restored_by'])]
class InboxArchive extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'restored_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'workflow';
    }

    public function auditLabel(): string
    {
        return 'Archived: '.($this->title ?? $this->item_id);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isArchived(): bool
    {
        return $this->restored_at === null;
    }
}
