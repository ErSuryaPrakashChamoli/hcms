<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity HP-8g (Performance → Bell curve): the target share of employees at one rating level, for a cycle or
 * (no cycle) as the default of every cycle whose scale has that level. Written only by PerformanceDistribution, which
 * keeps each set adding up to 100.
 */
#[Fillable(['tenant_id', 'performance_cycle_id', 'level_value', 'target_pct'])]
class PerformanceDistributionTarget extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['level_value' => 'float', 'target_pct' => 'float'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Distribution target '.rtrim(rtrim(number_format((float) $this->level_value, 2, '.', ''), '0'), '.').' ('.($this->performance_cycle_id ? 'cycle #'.$this->performance_cycle_id : 'default').')';
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }
}
