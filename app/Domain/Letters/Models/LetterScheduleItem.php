<?php

namespace App\Domain\Letters\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity WO-13: what one schedule did for one employee and occurrence (the event date, or the run date of a one-time
 * schedule): unique, so a schedule never writes the same letter twice. Written by LetterSchedules without automatic
 * auditing (the letter itself is audited when it is generated; refusals are only kept here).
 */
#[Fillable(['tenant_id', 'letter_schedule_id', 'employee_id', 'occurrence', 'letter_id', 'outcome', 'message'])]
class LetterScheduleItem extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const OUTCOMES = ['generated' => 'Generated', 'issued' => 'Issued', 'refused' => 'Refused', 'failed' => 'Failed'];

    protected function casts(): array
    {
        return ['occurrence' => 'date'];
    }

    public function auditModule(): string
    {
        return 'letters';
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LetterSchedule::class, 'letter_schedule_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }
}
