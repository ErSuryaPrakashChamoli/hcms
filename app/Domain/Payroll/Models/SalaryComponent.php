<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A pay element (§30). Non-statutory components are fully tenant-configurable; statutory ones
 * (is_statutory) are created by the compliance pack and their behaviour is locked (§101).
 */
#[Fillable(['tenant_id', 'name', 'code', 'type', 'classification', 'calculation_method', 'formula', 'taxable', 'pf_applicable', 'esi_applicable', 'include_in_ctc', 'include_in_gross', 'is_recurring', 'is_proratable', 'is_arrear_eligible', 'is_statutory', 'sort_order', 'status', 'effective_from', 'effective_to'])]
class SalaryComponent extends Model
{
    /** Controlled component classification (Phase 4 §9); statutory lines are produced by the compliance engine. */
    public const TYPES = ['earning', 'deduction', 'employer_contribution', 'reimbursement'];

    use Auditable, BelongsToTenant, HasEffectiveDates;

    /** Codes reserved for statutory lines produced by the compliance engine. */
    public const STATUTORY_CODES = ['PF_EE', 'PF_ER', 'PF_ADMIN', 'ESI_EE', 'ESI_ER', 'PT', 'LWF_EE', 'LWF_ER', 'TDS'];

    protected static function booted(): void
    {
        static::saving(function (self $component): void {
            $component->code = strtoupper(trim((string) $component->code));

            if ($component->exists && $component->is_statutory && ! app(TenantContext::class)->isBypassed()) {
                foreach (['type', 'classification', 'calculation_method', 'formula', 'is_statutory', 'code'] as $locked) {
                    if ($component->isDirty($locked)) {
                        throw new \RuntimeException('Statutory components are maintained by the compliance pack and cannot be changed.');
                    }
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'taxable' => 'boolean',
            'pf_applicable' => 'boolean',
            'esi_applicable' => 'boolean',
            'include_in_ctc' => 'boolean',
            'include_in_gross' => 'boolean',
            'is_recurring' => 'boolean',
            'is_proratable' => 'boolean',
            'is_arrear_eligible' => 'boolean',
            'is_statutory' => 'boolean',
            'sort_order' => 'integer',
            'status' => ActiveStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function isEarning(): bool
    {
        return in_array($this->type, ['earning', 'reimbursement'], true);
    }

    public function variableName(): string
    {
        return strtolower($this->code);
    }
}
