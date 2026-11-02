<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HROne parity TX-3 ("Process attendance" / "Unprocess attendance"): a company's attendance month as HR closed or
 * reopened it. While `closed` (and attendance.month_close.enabled is on) the days from start_date to end_date refuse
 * requests and overrides like locked days and keep their calculated values; written only by AttendanceMonthCloses.
 */
#[Fillable(['tenant_id', 'company_id', 'year', 'month', 'start_date', 'end_date', 'status', 'closed_by', 'closed_at', 'close_note', 'reopened_by', 'reopened_at', 'reopen_reason'])]
class AttendanceMonthClose extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['open' => 'Open', 'closed' => 'Closed'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Attendance month '.$this->label();
    }

    public function label(): string
    {
        return Carbon::create((int) $this->year, (int) $this->month, 1)->format('M Y');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
