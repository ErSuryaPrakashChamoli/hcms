<?php

namespace App\Domain\Communication\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 13: one employee in an announcement's audience snapshot, with its delivery state.
 *
 * pending → sent (at least one channel accepted it) | failed (every channel failed, retried) |
 * skipped (no account, or every channel switched off for an optional category).
 *
 * "Sent" means the in-app inbox entry was written or the mailer accepted the email. Nothing here
 * claims provider delivery, because no channel reports it. Reading and acknowledgement live in
 * announcement_reads. The message body is never copied here. Derived tracking rows (not Auditable,
 * volume); the snapshot itself is audited as AUDIENCE_USED with an operation id.
 */
#[Fillable(['tenant_id', 'announcement_id', 'employee_id', 'user_id', 'status', 'channels', 'skipped_reason', 'attempts', 'processed_at', 'error'])]
class CommunicationRecipient extends Model
{
    use BelongsToTenant;
    use ScopedByEmployee;

    protected function casts(): array
    {
        return ['channels' => 'array', 'attempts' => 'integer', 'processed_at' => 'datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
