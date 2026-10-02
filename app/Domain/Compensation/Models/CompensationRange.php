<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 11: the pay range permitted for a grade (Grade → range → minimum / midpoint / maximum),
 * optionally narrowed to a structure, company, job family or designation. Effective-dated; a draft is
 * edited, submitted and approved by a second person; approved definitions never change (a later
 * version closes the previous one). Midpoint is optional when the tenant's range model is min / max.
 */
#[Fillable(['tenant_id', 'grade_id', 'salary_structure_id', 'company_id', 'job_family_id', 'designation_id', 'version', 'status', 'currency', 'frequency', 'minimum', 'midpoint', 'maximum', 'effective_from', 'effective_to', 'applicability_key', 'notes', 'prepared_by', 'submitted_at', 'approved_by', 'approved_at', 'decision_note', 'lock_version'])]
class CompensationRange extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'approved' => 'Approved', 'superseded' => 'Superseded', 'archived' => 'Archived'];

    public const CONTENT = ['grade_id', 'salary_structure_id', 'company_id', 'job_family_id', 'designation_id', 'currency', 'frequency', 'minimum', 'midpoint', 'maximum', 'effective_from', 'applicability_key', 'version'];

    protected $attributes = ['status' => 'draft', 'frequency' => 'annual', 'version' => 1, 'lock_version' => 0];

    protected static function booted(): void
    {
        static::updating(function (self $range): void {
            $was = $range->getRawOriginal('status');
            $content = array_intersect(array_keys($range->getDirty()), self::CONTENT);
            if ($content !== [] && $was !== 'draft') {
                throw new CompensationRuleViolation('An approved range never changes; propose a new version ('.implode(', ', $content).').');
            }
            if ($range->isDirty('effective_to') && in_array($was, ['approved', 'superseded'], true) && $range->getRawOriginal('effective_to') !== null) {
                throw new CompensationRuleViolation('An approved range is closed only once.');
            }
        });
        static::deleting(function (): void {
            throw new CompensationRuleViolation('Ranges are never deleted; archive a draft instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'minimum' => 'decimal:2',
            'midpoint' => 'decimal:2',
            'maximum' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'version' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'compensation';
    }

    public function auditLabel(): string
    {
        return 'Range '.($this->grade?->code ?? $this->grade_id).' v'.$this->version.' from '.$this->effective_from?->toDateString();
    }

    /** Range values are pay information (financial classification). */
    public function auditSensitiveAttributes(): array
    {
        return ['minimum', 'midpoint', 'maximum'];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobFamily(): BelongsTo
    {
        return $this->belongsTo(JobFamily::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Range values on an annual basis (a monthly range is ×12). */
    public function annual(string $field): ?float
    {
        $value = $this->getAttribute($field);

        return $value === null ? null : round((float) $value * ($this->frequency === 'monthly' ? 12 : 1), 2);
    }

    /** How specific the range is: designation > job family > company > structure > grade only. */
    public function specificity(): int
    {
        return ($this->designation_id ? 8 : 0) + ($this->job_family_id ? 4 : 0) + ($this->company_id ? 2 : 0) + ($this->salary_structure_id ? 1 : 0);
    }

    public static function keyFor(array $data): string
    {
        return implode('|', [
            (int) ($data['grade_id'] ?? 0), (int) ($data['salary_structure_id'] ?? 0), (int) ($data['company_id'] ?? 0),
            (int) ($data['job_family_id'] ?? 0), (int) ($data['designation_id'] ?? 0),
            strtoupper((string) ($data['currency'] ?? '')), (string) ($data['frequency'] ?? 'annual'),
        ]);
    }
}
