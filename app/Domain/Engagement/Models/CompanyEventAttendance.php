<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity GO-15: whether an invitee attended one session of a company event, who marked it and when (one row per
 * session and employee; marking again changes it, audited). Written only by `CompanyEvents::markAttendance()`.
 */
#[Fillable(['tenant_id', 'session_id', 'employee_id', 'attended', 'marked_by', 'marked_at'])]
class CompanyEventAttendance extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    protected function casts(): array
    {
        return ['attended' => 'boolean', 'marked_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return 'Event attendance #'.$this->getKey();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CompanyEventSession::class, 'session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
