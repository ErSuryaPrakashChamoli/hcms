<?php

namespace App\Domain\Employment\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Partners\Models\Vendor;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data import (Workforce → Contract): a contract of a contract staff member — the agency (vendor) that supplies them,
 * its number and dates. Written only through Employment\Services\EmployeeContracts.
 */
#[Fillable(['tenant_id', 'employee_id', 'vendor_id', 'contract_number', 'start_date', 'end_date', 'notes'])]
class EmployeeContract extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function auditModule(): string
    {
        return 'employment';
    }

    public function auditLabel(): string
    {
        return "Contract {$this->contract_number}";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Whether the contract covers today. */
    public function isCurrent(): bool
    {
        $today = now()->toDateString();

        return $this->start_date->toDateString() <= $today && ($this->end_date === null || $this->end_date->toDateString() >= $today);
    }
}
