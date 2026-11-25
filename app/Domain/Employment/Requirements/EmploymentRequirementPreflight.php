<?php

namespace App\Domain\Employment\Requirements;

use App\Domain\Configuration\Models\Policy;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remediation I.7 / I.13: one pre-flight of an employment data requirements policy (table
 * employment_requirement_preflights). Small organisations run it in the request (mode sync); above the threshold it
 * runs on the queue (RunEmploymentRequirementsPreflight) and the page follows its progress. Only a completed run whose
 * fingerprints are still current authorises Block; a pending, running, failed, superseded or cancelled one never does.
 */
#[Fillable(['tenant_id', 'ulid', 'policy_id', 'company_id', 'purpose', 'mode', 'status', 'settings', 'end_paths', 'accept_gaps', 'fingerprint', 'evidence_fingerprint',
    'applicability_fingerprint', 'overrides_fingerprint', 'effective_on', 'total', 'processed', 'report', 'outcome', 'error', 'reason', 'requested_by', 'started_at', 'finished_at'])]
class EmploymentRequirementPreflight extends Model
{
    use BelongsToTenant;

    public const REVIEW = 'review';

    public const SWITCH = 'switch';

    public const COMPANY = 'company';

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** A newer run of the same policy and purpose took over, or the policy changed while it ran. */
    public const SUPERSEDED = 'superseded';

    public const CANCELLED = 'cancelled';

    /** Statuses a run can still move from. */
    public const OPEN = [self::PENDING, self::RUNNING];

    protected $table = 'employment_requirement_preflights';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'end_paths' => 'array',
            'report' => 'array',
            'accept_gaps' => 'boolean',
            'effective_on' => 'date',
            'total' => 'integer',
            'processed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Percentage read, for the progress bar (null before the total is known). */
    public function progress(): ?int
    {
        return $this->total === null || $this->total === 0 ? null : (int) min(100, floor($this->processed * 100 / $this->total));
    }
}
