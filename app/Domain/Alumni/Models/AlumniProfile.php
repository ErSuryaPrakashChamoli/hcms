<?php

namespace App\Domain\Alumni\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A former employee's standing relationship with the company (§62). */
#[Fillable(['tenant_id', 'employee_id', 'exit_case_id', 'personal_email', 'phone', 'last_designation', 'last_department', 'joined_on', 'exited_on', 'exit_type', 'is_rehire_eligible', 'portal_enabled', 'consent_to_contact', 'notes'])]
class AlumniProfile extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['joined_on' => 'date', 'exited_on' => 'date', 'is_rehire_eligible' => 'boolean', 'portal_enabled' => 'boolean', 'consent_to_contact' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'alumni';
    }

    public function auditLabel(): string
    {
        return 'Alumni profile';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['personal_email', 'phone', 'notes'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function exitCase(): BelongsTo
    {
        return $this->belongsTo(ExitCase::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(AlumniRequest::class)->latest('id');
    }
}
