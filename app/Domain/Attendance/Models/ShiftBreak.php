<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A configured break inside a shift; unpaid breaks reduce worked minutes, paid ones do not (Phase 2 §11). */
#[Fillable(['tenant_id', 'shift_id', 'name', 'starts_at', 'ends_at', 'duration_minutes', 'is_paid', 'sort_order'])]
class ShiftBreak extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer', 'is_paid' => 'boolean', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->duration_minutes} min, ".($this->is_paid ? 'paid' : 'unpaid').')';
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
