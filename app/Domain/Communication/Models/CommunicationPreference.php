<?php

namespace App\Domain\Communication\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 13: an employee's channel choice for one OPTIONAL communication category. Mandatory types
 * (peopleos.communication.mandatory_types) and transactional notifications never consult it.
 */
#[Fillable(['tenant_id', 'employee_id', 'category', 'in_app', 'email'])]
class CommunicationPreference extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected function casts(): array
    {
        return ['in_app' => 'boolean', 'email' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'communication';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
