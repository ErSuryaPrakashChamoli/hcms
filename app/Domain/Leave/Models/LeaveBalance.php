<?php

namespace App\Domain\Leave\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cached summary of the ledger; recomputed by LeaveBalances after every movement. */
#[Fillable(['tenant_id', 'employee_id', 'leave_type_id', 'period_year', 'opening', 'accrued', 'adjusted', 'used', 'pending', 'encashed', 'lapsed', 'closing', 'computed_at'])]
class LeaveBalance extends Model
{
    use BelongsToTenant;
    use ScopedByEmployee;

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'opening' => 'decimal:2', 'accrued' => 'decimal:2', 'adjusted' => 'decimal:2', 'used' => 'decimal:2',
            'pending' => 'decimal:2', 'encashed' => 'decimal:2', 'lapsed' => 'decimal:2', 'closing' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function available(): float
    {
        return (float) $this->closing - (float) $this->pending;
    }
}
