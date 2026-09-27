<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'holiday_calendar_id', 'name', 'priority', 'conditions', 'status'])]
class HolidayCalendarRule extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['conditions' => 'array', 'priority' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class, 'holiday_calendar_id');
    }
}
