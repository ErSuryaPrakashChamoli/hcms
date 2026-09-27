<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only log of lifecycle state changes. The audit trail is separate and richer. */
#[Fillable(['tenant_id', 'employee_id', 'from_state', 'to_state', 'effective_date', 'reason', 'actor_id', 'metadata'])]
class EmployeeLifecycleTransition extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'from_state' => LifecycleState::class,
            'to_state' => LifecycleState::class,
            'effective_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
