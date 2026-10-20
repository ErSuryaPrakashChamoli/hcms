<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 11: one effective-dated version of a compensation structure — which payroll components it is
 * built from, in which order, with which overrides, for which companies / grades, in which currency.
 *
 * Draft → pending approval → scheduled (approved, later date) / active (in force) → superseded, or
 * archived. Only a draft is edited; from submission the content is frozen, and once approved only the
 * end date (closed once, when the next version is approved) and the status move. A correction is a new
 * version from a later date.
 */
#[Fillable(['tenant_id', 'salary_structure_id', 'version', 'status', 'effective_from', 'effective_to', 'currency', 'pay_frequency', 'company_id', 'grade_ids', 'change_note', 'checksum', 'prepared_by', 'submitted_at', 'approved_by', 'approved_at', 'decision_note', 'archived_at', 'lock_version'])]
class SalaryStructureVersion extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'scheduled' => 'Approved — scheduled', 'active' => 'Active', 'superseded' => 'Superseded', 'archived' => 'Archived'];

    /** Approved versions: part of the structure's effective-dated history. */
    public const APPROVED = ['scheduled', 'active', 'superseded'];

    public const CONTENT = ['salary_structure_id', 'version', 'effective_from', 'currency', 'pay_frequency', 'company_id', 'grade_ids', 'change_note'];

    protected $attributes = ['status' => 'draft', 'currency' => 'INR', 'pay_frequency' => 'monthly', 'lock_version' => 0];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $was = $version->getRawOriginal('status');
            $content = array_intersect(array_keys($version->getDirty()), self::CONTENT);
            if ($content !== [] && $was !== 'draft') {
                throw new CompensationRuleViolation('Only a draft structure version is edited; a correction is a new version ('.implode(', ', $content).').');
            }
            if ($version->isDirty('effective_to') && in_array($was, self::APPROVED, true) && $version->getRawOriginal('effective_to') !== null) {
                throw new CompensationRuleViolation('An approved structure version is closed only once.');
            }
        });
        static::deleting(function (): void {
            throw new CompensationRuleViolation('Structure versions are never deleted; archive a draft instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'grade_ids' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'compensation';
    }

    public function auditLabel(): string
    {
        return ($this->structure?->code ?? 'Structure').' v'.$this->version;
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(SalaryStructureComponent::class)->orderBy('sort_order');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return in_array($this->status, self::APPROVED, true);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** A version applies to an employee's company and grade (null / empty lists = every one). */
    public function appliesTo(?int $companyId, ?int $gradeId): bool
    {
        return ($this->company_id === null || $companyId === null || (int) $this->company_id === $companyId)
            && (empty($this->grade_ids) || $gradeId === null || in_array($gradeId, array_map('intval', $this->grade_ids), true));
    }
}
