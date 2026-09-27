<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One execution of a report, with its export file when produced. */
#[Fillable(['tenant_id', 'report_id', 'report_schedule_id', 'run_by', 'format', 'row_count', 'disk', 'path', 'status', 'error', 'started_at', 'finished_at'])]
class ReportRun extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['row_count' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function hasFile(): bool
    {
        return $this->path !== null;
    }
}
