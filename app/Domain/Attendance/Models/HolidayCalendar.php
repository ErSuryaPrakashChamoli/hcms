<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'name', 'code', 'year', 'status'])]
class HolidayCalendar extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['year' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class)->orderBy('date');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(HolidayCalendarRule::class);
    }
}
