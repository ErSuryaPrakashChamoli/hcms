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
 * HROne parity TX-3 ("Process attendance" in the background): one queued calculation of a company's attendance month for
 * a set of employees. Started and finished through AttendanceMonthRuns (both audited); the done / failed counters move
 * with each employee's job and are not audited one by one.
 */
#[Fillable(['tenant_id', 'company_id', 'year', 'month', 'start_date', 'end_date', 'status', 'total', 'done', 'failed', 'filters', 'started_by', 'finished_at'])]
class AttendanceMonthRun extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['running' => 'Running', 'completed' => 'Completed'];

    protected $attributes = ['status' => 'running'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'start_date' => 'date', 'end_date' => 'date', 'total' => 'integer', 'done' => 'integer', 'failed' => 'integer', 'filters' => 'array', 'finished_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Attendance run '.$this->label();
    }

    public function label(): string
    {
        return Carbon::create((int) $this->year, (int) $this->month, 1)->format('M Y');
    }

    /** Share of employees reached (done or failed), 0–100. */
    public function progress(): int
    {
        return $this->total > 0 ? (int) min(100, floor(($this->done + $this->failed) * 100 / $this->total)) : 100;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }
}
