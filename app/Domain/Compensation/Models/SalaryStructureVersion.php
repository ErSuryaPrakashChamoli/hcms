<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 11: one effective-dated version of a compensation structure — which payroll components it is
 * built from, in which order, with which overrides, for which companies / grades / other position
 * dimensions, in which currency.
 *
 * Draft → pending approval → scheduled (approved, later date) / active (in force) → superseded, or
 * archived. Only a draft is edited; from submission the content is frozen, and once approved only the
 * end date (closed once, when the next version is approved) and the status move. A correction is a new
 * version from a later date.
 */
#[Fillable(['tenant_id', 'salary_structure_id', 'version', 'status', 'effective_from', 'effective_to', 'currency', 'pay_frequency', 'company_id', 'grade_ids', 'applicability', 'parameters', 'change_note', 'checksum', 'prepared_by', 'submitted_at', 'approved_by', 'approved_at', 'decision_note', 'archived_at', 'lock_version'])]
class SalaryStructureVersion extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'scheduled' => 'Approved — scheduled', 'active' => 'Active', 'superseded' => 'Superseded', 'archived' => 'Archived'];

    /** Approved versions: part of the structure's effective-dated history. */
    public const APPROVED = ['scheduled', 'active', 'superseded'];

    public const CONTENT = ['salary_structure_id', 'version', 'effective_from', 'currency', 'pay_frequency', 'company_id', 'grade_ids', 'applicability', 'parameters', 'change_note'];

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
            'parameters' => 'array',
            'applicability' => 'array',
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

    /**
     * The lines as the structure formula check and preview read them, in calculation order.
     *
     * @return list<array{component: SalaryComponent, formula_override: ?string, sort_order: int}>
     */
    public function formulaLines(): array
    {
        return $this->components()->with('component')->get()->filter(fn (SalaryStructureComponent $line) => $line->component !== null)
            ->map(fn (SalaryStructureComponent $line) => ['component' => $line->component, 'formula_override' => $line->formula_override, 'sort_order' => (int) $line->sort_order])
            ->values()->all();
    }

    /**
     * Position dimensions a version can be narrowed to besides its company: column => label. Grades keep
     * their own column (grade_ids); every other dimension lives in `applicability`.
     *
     * @return array<string, string>
     */
    public static function dimensions(): array
    {
        return collect(EmployeePosition::DIMENSIONS)->except('company_id')->map(fn (array $d) => $d[1])->all();
    }

    /** @return array<string, list<int>> position column => the ids the version is narrowed to (grade included) */
    public function restrictions(): array
    {
        $all = ['grade_id' => $this->grade_ids ?? []] + (array) ($this->applicability ?? []);

        return collect(self::dimensions())->map(fn ($label, string $column) => array_map('intval', (array) ($all[$column] ?? [])))->filter()->all();
    }

    /**
     * The first dimension of an employee's position (column => id) the version does not cover, as a
     * lower-case label; null when it applies. Within one dimension the listed values are alternatives;
     * every narrowed dimension must match, so a position without a value there does not match.
     */
    public function mismatch(array $position): ?string
    {
        if ($this->company_id !== null && filled($position['company_id'] ?? null) && (int) $position['company_id'] !== (int) $this->company_id) {
            return 'company';
        }
        foreach ($this->restrictions() as $column => $ids) {
            if (! in_array((int) ($position[$column] ?? 0), $ids, true)) {
                return strtolower(self::dimensions()[$column]);
            }
        }

        return null;
    }

    /** A version applies to a position when it covers its company and every dimension it is narrowed by. */
    public function appliesTo(array $position): bool
    {
        return $this->mismatch($position) === null;
    }
}
