<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** Data import DI-1: an employee on a project, with a role, an allocation and the dates they are on it. */
#[Fillable(['tenant_id', 'project_id', 'employee_id', 'role', 'allocation_percent', 'from_date', 'to_date'])]
class ProjectMember extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    protected static function booted(): void
    {
        static::saving(function (ProjectMember $member): void {
            $a = $member->getAttributes();
            if (($a['from_date'] ?? null) !== null && ($a['to_date'] ?? null) !== null && substr((string) $a['to_date'], 0, 10) < substr((string) $a['from_date'], 0, 10)) {
                throw new RuntimeException('A member leaves the project on or after the day they join it.');
            }
            $allocation = $a['allocation_percent'] ?? null;
            if ($allocation !== null && ((float) $allocation <= 0 || (float) $allocation > 100)) {
                throw new RuntimeException('An allocation is a percentage above 0 and up to 100.');
            }
        });
    }

    protected function casts(): array
    {
        return ['allocation_percent' => 'decimal:2', 'from_date' => 'date', 'to_date' => 'date'];
    }

    public function auditLabel(): string
    {
        return 'Project member #'.$this->getKey();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
