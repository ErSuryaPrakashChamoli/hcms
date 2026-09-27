<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'work_schedule_id', 'name', 'priority', 'conditions', 'status', 'effective_from', 'effective_to'])]
class WorkScheduleRule extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    protected function casts(): array
    {
        return ['conditions' => 'array', 'priority' => 'integer', 'status' => ActiveStatus::class, 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }
}
