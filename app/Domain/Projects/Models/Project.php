<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Services\Projects;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Data import DI-1 (Project Management → Projects): a project with an optional customer and company, its dates, a
 * manager, whether its time is billable and where it stands. Its people are project_members (Projects service). The
 * dates read start to end, and the customer, company and manager are the organisation's own (checked on every save).
 */
#[Fillable(['tenant_id', 'code', 'name', 'customer_id', 'company_id', 'start_date', 'end_date', 'manager_employee_id', 'is_billable', 'status', 'description'])]
class Project extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'planned', 'is_billable' => false];

    protected static function booted(): void
    {
        static::saving(function (Project $project): void {
            $a = $project->getAttributes();
            if (($a['start_date'] ?? null) !== null && ($a['end_date'] ?? null) !== null && substr((string) $a['end_date'], 0, 10) < substr((string) $a['start_date'], 0, 10)) {
                throw new RuntimeException('A project ends on or after the day it starts.');
            }
            foreach (['customer_id' => [Customer::class, 'customer'], 'company_id' => [Company::class, 'company'], 'manager_employee_id' => [Employee::class, 'manager']] as $column => [$class, $what]) {
                if ($project->isDirty($column) && ($a[$column] ?? null) !== null
                    && ! $class::query()->withoutGlobalScope(AccessScope::class)->whereKey($a[$column])->exists()) {
                    throw new RuntimeException("The project's {$what} is not on file.");
                }
            }
            // Decision D4: a new manager is a current or joining employee (one who later leaves stays on the record).
            if ($project->isDirty('manager_employee_id') && ($a['manager_employee_id'] ?? null) !== null && Projects::isGone((int) $a['manager_employee_id'])) {
                throw new RuntimeException('The manager has left or never joined: a project manager is a current or joining employee.');
            }
        });
    }

    protected function casts(): array
    {
        return ['status' => ProjectStatus::class, 'is_billable' => 'boolean', 'start_date' => 'date', 'end_date' => 'date'];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }
}
