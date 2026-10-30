<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne setup parity HP-8a: a unit of measurement for goals and key results (Percent, the base currency, Rating,
 * Units, or the organisation's own). measure_type is how progress is calculated (null: the goal chooses); the default
 * target pre-fills new goals; applicability (rule conditions) limits whose goals may use it; is_default pre-selects
 * it. Units added by employees (performance.units.employee_can_add) are not admin units: only their creator and HR
 * see them. A unit can carry the KPI rating formula its goals use by default (HP-8c).
 */
#[Fillable(['tenant_id', 'code', 'name', 'measure_type', 'default_target', 'is_default', 'is_system', 'is_admin', 'created_by', 'applicability', 'status', 'kpi_rating_formula_id'])]
class PerformanceUnit extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'is_default' => false, 'is_system' => false, 'is_admin' => true];

    protected static function booted(): void
    {
        static::saving(function (self $unit): void {
            $unit->code = strtoupper(trim((string) $unit->code));
            if ($unit->code === '' || trim((string) $unit->name) === '') {
                throw new RuntimeException('A unit needs a label and a code.');
            }
            if ($unit->measure_type !== null && $unit->measure_type !== '' && ! array_key_exists($unit->measure_type, (array) config('peopleos.performance.measure_types'))) {
                throw new RuntimeException("Unknown way to calculate progress [{$unit->measure_type}].");
            }
            $unit->measure_type = $unit->measure_type ?: null;
            if ($unit->exists && $unit->getRawOriginal('is_system') && $unit->isDirty('code')) {
                throw new RuntimeException('A standard unit keeps its code.');
            }
            if ($unit->is_default && ! $unit->is_admin) {
                throw new RuntimeException('Only an organisation unit can be the default unit.');
            }
        });
        static::saved(function (self $unit): void {
            if ($unit->is_default) {
                static::query()->whereKeyNot($unit->getKey())->where('is_default', true)->get()->each(fn (self $other) => $other->update(['is_default' => false]));
            }
        });
        static::deleting(function (self $unit): void {
            if ($unit->is_system) {
                throw new RuntimeException('A standard unit cannot be deleted; make it inactive instead.');
            }
        });
    }

    protected function casts(): array
    {
        return ['default_target' => 'decimal:2', 'is_default' => 'boolean', 'is_system' => 'boolean', 'is_admin' => 'boolean', 'applicability' => 'array'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function kpiRatingFormula(): BelongsTo
    {
        return $this->belongsTo(KpiRatingFormula::class);
    }

    /** Whether a goal owned by this employee may use the unit (no rule conditions = everyone; no owner = organisation goals). */
    public function appliesTo(?Employee $employee): bool
    {
        if (empty($this->applicability)) {
            return true;
        }
        if ($employee === null) {
            return false;
        }

        return app(RuleEngine::class)->matches($this->applicability, app(EmployeeRuleContext::class)->build($employee, today()));
    }

    /** Admin units and the viewer's own; HR (performance.manage) sees every unit. */
    public function visibleTo(?User $viewer): bool
    {
        return $this->is_admin || $viewer === null || $viewer->hasPermission('performance.manage') || (int) $this->created_by === (int) $viewer->getKey();
    }

    public function display(): string
    {
        return $this->name === $this->code ? $this->name : "{$this->name} ({$this->code})";
    }
}
