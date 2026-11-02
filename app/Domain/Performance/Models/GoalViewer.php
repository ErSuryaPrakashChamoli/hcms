<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity GO-4 (HROne "Visible to", owner decision PER-5 / D-GO5): one person on the visible-to list of a private
 * organisation goal. Written only by Goals (create / update), audited; the goal visibility scope reads the table directly.
 */
#[Fillable(['tenant_id', 'goal_id', 'employee_id', 'created_by'])]
class GoalViewer extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Goal '.$this->goal_id.' visible to employee '.$this->employee_id;
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
