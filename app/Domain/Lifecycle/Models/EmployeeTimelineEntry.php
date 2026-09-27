<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One line on the People Timeline (blueprint §18). */
#[Fillable(['tenant_id', 'employee_id', 'occurred_on', 'category', 'title', 'description', 'source_type', 'source_id', 'actor_id', 'metadata'])]
class EmployeeTimelineEntry extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'metadata' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
