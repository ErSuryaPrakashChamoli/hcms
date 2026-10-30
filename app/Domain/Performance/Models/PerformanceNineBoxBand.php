<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne setup parity HP-8g (Performance → 9 box): one band (low / moderate / high) of the performance or potential axis,
 * for a cycle or (no cycle) as the default. A value at or above `from_value` and below the next band's start falls in
 * the band. Written only by NineBox, which keeps the three bands of an axis ordered and contiguous.
 */
#[Fillable(['tenant_id', 'performance_cycle_id', 'axis', 'band', 'from_value', 'to_value'])]
class PerformanceNineBoxBand extends Model
{
    use Auditable, BelongsToTenant;

    public const AXES = ['performance', 'potential'];

    public const BANDS = ['low', 'moderate', 'high'];

    protected static function booted(): void
    {
        static::saving(function (self $band): void {
            if (! in_array($band->axis, self::AXES, true) || ! in_array($band->band, self::BANDS, true)) {
                throw new RuntimeException('A 9-box band is low, moderate or high on the performance or potential axis.');
            }
        });
    }

    protected function casts(): array
    {
        return ['from_value' => 'float', 'to_value' => 'float'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return "9-box band {$this->axis} {$this->band} (".($this->performance_cycle_id ? 'cycle #'.$this->performance_cycle_id : 'default').')';
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }
}
