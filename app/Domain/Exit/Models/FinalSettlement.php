<?php

namespace App\Domain\Exit\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Full & final settlement (§60): every line explainable via its basis. draft → calculated → approved → paid. */
#[Fillable(['tenant_id', 'exit_case_id', 'employee_id', 'status', 'total_earnings', 'total_deductions', 'net_amount', 'inputs', 'calculated_at', 'approved_by', 'approved_at', 'paid_at', 'payment_reference', 'notes'])]
class FinalSettlement extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['total_earnings' => 'decimal:2', 'total_deductions' => 'decimal:2', 'net_amount' => 'decimal:2', 'inputs' => 'array', 'calculated_at' => 'datetime', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'exit';
    }

    public function auditLabel(): string
    {
        return 'Full & final settlement';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['total_earnings', 'total_deductions', 'net_amount', 'inputs'];
    }

    public function exitCase(): BelongsTo
    {
        return $this->belongsTo(ExitCase::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinalSettlementLine::class)->orderBy('sort_order');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'calculated'], true);
    }
}
