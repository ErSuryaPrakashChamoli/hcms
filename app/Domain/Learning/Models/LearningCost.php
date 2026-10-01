<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8: a learning cost (course, provider, employee, travel, other). Visible only with
 * learning.costs. Never posted to payroll, salary or reimbursement — a future expense module may
 * read it through a contract.
 */
#[Fillable(['tenant_id', 'learning_enrolment_id', 'employee_id', 'course_id', 'training_session_id', 'learning_provider_id', 'cost_type', 'amount', 'currency', 'incurred_on', 'note', 'created_by'])]
class LearningCost extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPES = ['course' => 'Course fee', 'provider' => 'Provider fee', 'employee' => 'Employee cost', 'travel' => 'Travel', 'other' => 'Other'];

    protected static function booted(): void
    {
        static::saving(function (self $c) {
            if (! array_key_exists($c->cost_type, self::TYPES)) {
                throw new \RuntimeException("Unknown cost type '{$c->cost_type}'.");
            }
            if ((float) $c->amount < 0) {
                throw new \RuntimeException('A learning cost cannot be negative.');
            }
            $c->currency = strtoupper((string) $c->currency);
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'incurred_on' => 'date'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['amount'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LearningProvider::class, 'learning_provider_id');
    }
}
