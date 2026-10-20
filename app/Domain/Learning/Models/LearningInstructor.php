<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8: an internal or external instructor. An internal instructor references the existing
 * Employee (no second person record); the name is taken from that person.
 */
#[Fillable(['tenant_id', 'name', 'employee_id', 'learning_provider_id', 'email', 'bio', 'specialisation', 'status'])]
class LearningInstructor extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $i) {
            if ($i->employee_id && blank($i->name)) {
                $i->name = (string) Employee::query()->with('person')->find($i->employee_id)?->person?->full_name;
            }
            if (blank($i->name)) {
                throw new \RuntimeException('An instructor needs a name or an employee.');
            }
        });
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return 'Instructor '.$this->name;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LearningProvider::class, 'learning_provider_id');
    }

    public function isInternal(): bool
    {
        return $this->employee_id !== null;
    }
}
