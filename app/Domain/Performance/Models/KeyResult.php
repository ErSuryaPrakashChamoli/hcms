<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A measurable key result under an objective (OKR). */
#[Fillable(['tenant_id', 'goal_id', 'title', 'measure_type', 'start_value', 'target_value', 'current_value', 'unit', 'weight', 'progress', 'sort_order'])]
class KeyResult extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['start_value' => 'decimal:2', 'target_value' => 'decimal:2', 'current_value' => 'decimal:2', 'weight' => 'integer', 'progress' => 'decimal:2', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
