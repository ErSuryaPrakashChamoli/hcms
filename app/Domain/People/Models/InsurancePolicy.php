<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne parity MS-5 (matrix 22 §22.2.5 #4–7): a health insurance policy an employee is covered by — insurer name and code,
 * policy number, sum assured, issue date, valid until, and the e-card (a file on the documents disk, served only through
 * ParityFileController's audited "insurance-ecard" link). Who it covers are its InsuredMembers. Written only through
 * InsuranceRecords (employee.sensitive.update, organisation scope); read on the Employee 360 with `viewSensitive` and the
 * CA-6 section `health_insurance`, and by the employee on My profile.
 */
#[Fillable(['tenant_id', 'employee_id', 'insurer_name', 'insurer_code', 'policy_number', 'sum_assured', 'issued_on', 'valid_until', 'ecard_path', 'ecard_name'])]
class InsurancePolicy extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    protected $table = 'employee_insurance_policies';

    protected function casts(): array
    {
        return [
            'sum_assured' => 'decimal:2',
            'issued_on' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function auditLabel(): string
    {
        return 'Health insurance '.$this->insurer_name.' '.$this->policy_number;
    }

    /** "Star Health · 12345/2026" — how the policy is named in lists and forms. */
    public function label(): string
    {
        return trim($this->insurer_name.' · '.$this->policy_number, ' ·');
    }

    public function hasEcard(): bool
    {
        return filled($this->getAttributes()['ecard_path'] ?? null);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(InsuredMember::class, 'policy_id');
    }
}
