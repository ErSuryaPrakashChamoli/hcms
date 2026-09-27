<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'holiday_calendar_id', 'date', 'name', 'type', 'is_half_day'])]
class Holiday extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['date' => 'date', 'is_half_day' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return $this->name.' ('.$this->date?->toDateString().')';
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class, 'holiday_calendar_id');
    }
}
