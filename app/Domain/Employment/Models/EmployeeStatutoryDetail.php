<?php

namespace App\Domain\Employment\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sensitive (blueprint §80): identifiers encrypted at rest, masked in audit, viewing audited. */
#[Fillable(['tenant_id', 'employee_id', 'pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number', 'pf_applicable', 'esic_applicable', 'pt_applicable', 'tax_regime', 'pt_state_code', 'metadata'])]
#[Hidden(['pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number'])]
class EmployeeStatutoryDetail extends Model
{
    use Auditable, BelongsToTenant;

    public const SENSITIVE = ['pan', 'aadhaar_reference', 'uan', 'pf_number', 'esic_number'];

    protected function casts(): array
    {
        return [
            'pan' => 'encrypted',
            'aadhaar_reference' => 'encrypted',
            'uan' => 'encrypted',
            'pf_number' => 'encrypted',
            'esic_number' => 'encrypted',
            'pf_applicable' => 'boolean',
            'esic_applicable' => 'boolean',
            'pt_applicable' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return 'Statutory details';
    }

    public function auditSensitiveAttributes(): array
    {
        return self::SENSITIVE;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public static function mask(?string $value, int $keep = 4): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('•', max(mb_strlen($value) - $keep, 0)).mb_substr($value, -$keep);
    }
}
