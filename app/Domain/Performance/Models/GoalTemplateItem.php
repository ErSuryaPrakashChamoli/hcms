<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * HROne setup parity HP-8e: one KRA of a goal template, or (parent_item_id set) one KPI under a KRA. Assigning the
 * template creates a goal per KRA with its KPIs as key results (GoalTemplates::assign).
 */
#[Fillable(['tenant_id', 'goal_template_id', 'parent_item_id', 'kra_id', 'title', 'description', 'measure_type', 'unit_id', 'start_value', 'target_value', 'weight', 'is_reverse', 'perspective_id', 'kpi_rating_formula_id', 'sort_order'])]
class GoalTemplateItem extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['measure_type' => 'percentage', 'weight' => 0, 'start_value' => 0, 'is_reverse' => false];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            // A KPI belongs to its KRA's template.
            if ($item->parent_item_id && ! $item->goal_template_id) {
                $item->goal_template_id = static::query()->whereKey($item->parent_item_id)->value('goal_template_id');
            }
            if (trim((string) $item->title) === '') {
                throw new RuntimeException('Each KRA and KPI of a goal template needs a title.');
            }
            if ((int) $item->weight < 0 || (int) $item->weight > 100) {
                throw new RuntimeException('A weightage is between 0 and 100.');
            }
            if (! array_key_exists((string) $item->measure_type, (array) config('peopleos.performance.measure_types'))) {
                throw new RuntimeException("Unknown measure [{$item->measure_type}].");
            }
            if ($item->parent_item_id) {
                $parent = static::query()->find($item->parent_item_id);
                if ($parent === null || $parent->parent_item_id !== null || (int) $parent->goal_template_id !== (int) $item->goal_template_id) {
                    throw new RuntimeException('A KPI belongs to a KRA of the same template.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['start_value' => 'decimal:2', 'target_value' => 'decimal:2', 'weight' => 'integer', 'is_reverse' => 'boolean', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(GoalTemplate::class, 'goal_template_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    /** The KPIs under this KRA. */
    public function kpis(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_id')->orderBy('sort_order')->orderBy('id');
    }

    public function kra(): BelongsTo
    {
        return $this->belongsTo(Kra::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(PerformanceUnit::class, 'unit_id');
    }

    public function perspective(): BelongsTo
    {
        return $this->belongsTo(PerformanceLookup::class, 'perspective_id');
    }

    public function kpiRatingFormula(): BelongsTo
    {
        return $this->belongsTo(KpiRatingFormula::class);
    }
}
