<?php

namespace App\Domain\Bgv\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Background verification case (§22): consent, checks, vendor reference, overall result. */
#[Fillable(['tenant_id', 'employee_id', 'provider', 'external_reference', 'status', 'overall_result', 'consent_given_at', 'consent_document_id', 'initiated_by', 'initiated_at', 'completed_at', 'notes'])]
class BgvCase extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['initiated' => 'Initiated', 'in_progress' => 'In progress', 'completed' => 'Completed', 'closed' => 'Closed'];

    public const RESULTS = ['pending' => 'Pending', 'clear' => 'Clear', 'discrepancy' => 'Discrepancy', 'failed' => 'Failed'];

    protected function casts(): array
    {
        return [
            'consent_given_at' => 'datetime',
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'bgv';
    }

    public function auditLabel(): string
    {
        return "BGV case #{$this->id}";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(BgvCheck::class)->orderBy('id');
    }

    public function consentDocument(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'consent_document_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['initiated', 'in_progress'], true);
    }
}
