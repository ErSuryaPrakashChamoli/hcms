<?php

namespace App\Domain\Leave\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Append-only movement on a leave account; positive credits, negative debits. */
#[Fillable(['tenant_id', 'employee_id', 'leave_type_id', 'period_year', 'entry_date', 'type', 'days', 'accrual_key', 'reference_type', 'reference_id', 'note', 'created_by'])]
class LeaveLedgerEntry extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'days' => 'decimal:2', 'period_year' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
