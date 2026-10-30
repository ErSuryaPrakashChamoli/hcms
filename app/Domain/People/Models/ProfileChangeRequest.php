<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\People\Support\ProfileEditSections;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity MS-3: one change an employee asked for on My profile, for one section (ProfileEditSections).
 *
 * - `payload` (encrypted, hidden, never audited) holds the operations with the new values until the request is decided;
 *   then it is purged (`purged_at`), as Phase 12 purges service requests.
 * - `fields` keeps, for the history, the field names with masked earlier / new values (masked at submit).
 * - `route`: direct (applied at once: the section needs no approval and no workflow took it), approval (the Approval
 *   Center: ProfileChangeApprovalSource) or workflow (a run on `profile.change_requested` decides it, P5).
 * - `open_key` ("employee:section") is set only while pending; unique per tenant, so one open request per section.
 * - Changes are written only by ProfileChanges, through the People / Employment actions. Requests are never deleted:
 *   they are withdrawn or rejected.
 */
#[Fillable(['tenant_id', 'employee_id', 'requested_by', 'section', 'route', 'status', 'comment', 'payload', 'fields', 'approver_permissions', 'document_ids', 'open_key', 'operation_id', 'decided_by', 'decided_at', 'decision_note', 'applied_at', 'applied_records', 'purged_at'])]
class ProfileChangeRequest extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];

    public const ROUTES = ['direct' => 'Applied at once', 'approval' => 'Approval Center', 'workflow' => 'Approval workflow'];

    protected $attributes = ['status' => 'pending', 'route' => null, 'decided_by' => null, 'decided_at' => null, 'purged_at' => null];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'fields' => 'array',
            'approver_permissions' => 'array',
            'document_ids' => 'array',
            'applied_records' => 'array',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'people';
    }

    public function auditLabel(): string
    {
        return 'Profile change '.$this->reference().' ('.ProfileEditSections::label((string) $this->section).')';
    }

    /** The values never reach the audit trail; the employee's reason is masked there. @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...(array) config('peopleos.audit.ignored_attributes', []), 'payload', 'fields', 'open_key', 'operation_id', 'approver_permissions'];
    }

    /** @return list<string> */
    public function auditSensitiveAttributes(): array
    {
        return [...(array) config('peopleos.audit.sensitive_attributes', []), 'comment', 'decision_note'];
    }

    /** "PCR-000012": the reference the executed changes carry (audit approval reference, ChangeOrigin). */
    public function reference(): string
    {
        return 'PCR-'.str_pad((string) $this->getKey(), 6, '0', STR_PAD_LEFT);
    }

    public static function idFromReference(?string $reference): ?int
    {
        return $reference !== null && preg_match('/^PCR-(\d{1,18})$/', $reference, $m) === 1 ? (int) $m[1] : null;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
