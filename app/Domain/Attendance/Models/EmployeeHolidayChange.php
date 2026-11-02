<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HROne parity TX-12 ("Manage holiday" / "Change holiday", owner decision TIM-3 / D-TX3): one day of an employee's holidays
 * changed by HR on top of their holiday calendar. Effect `add` makes the day a holiday for the employee; effect `remove`
 * makes a calendar holiday an ordinary day for them. `action` is what HR chose: assign (an add row), remove (a remove row)
 * or replace (a remove row and an add row with the same `group_key`). Written only by EmployeeHolidayChanges; read by
 * HolidayResolver after the calendar, and the live row of the day wins. Never deleted: undoing marks the rows undone with
 * who, when and why, and `active` becomes null (one live row per employee and day).
 */
#[Fillable(['tenant_id', 'employee_id', 'group_key', 'action', 'effect', 'date', 'name', 'holiday_type', 'is_restricted', 'source', 'holiday_id', 'holiday_library_entry_id', 'reason', 'status', 'active', 'created_by', 'undone_by', 'undone_at', 'undo_reason'])]
class EmployeeHolidayChange extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    /** What HR chose (HROne "Choose your preference"). */
    public const ACTIONS = ['assign' => 'Assign holiday', 'remove' => 'Remove holiday', 'replace' => 'Replace holiday'];

    public const EFFECTS = ['add' => 'Holiday given', 'remove' => 'Holiday taken away'];

    public const SOURCES = ['typed' => 'Typed by HR', 'calendar' => 'From a holiday calendar', 'library' => 'From the holiday library'];

    public const STATUSES = ['active' => 'Live', 'undone' => 'Undone'];

    protected $attributes = ['status' => 'active', 'active' => true, 'is_restricted' => false];

    protected function casts(): array
    {
        return ['date' => 'date', 'is_restricted' => 'boolean', 'active' => 'boolean', 'undone_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Holiday change: '.$this->describe();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function adds(): bool
    {
        return $this->effect === 'add';
    }

    /** "Holiday given: Founders' Day (Sat, 14 Nov 2026), restricted" / "Holiday taken away: Diwali (Sun, 08 Nov 2026)". */
    public function describe(): string
    {
        return (self::EFFECTS[$this->effect] ?? (string) $this->effect).': '.$this->name.' ('.$this->date?->format('D, d M Y').')'
            .($this->adds() && $this->is_restricted ? ', restricted' : '');
    }

    /** The type label of the day ("Public holiday", "Restricted holiday" …). */
    public function typeLabel(): string
    {
        if ($this->adds() && $this->is_restricted) {
            return 'Restricted holiday';
        }

        return (string) config("peopleos.attendance.holiday_types.{$this->holiday_type}", ucfirst((string) $this->holiday_type));
    }

    /**
     * The holiday the employee has on the day, as HolidayResolver::holidayOn() answers it (never saved): name and type of
     * the change, no calendar. A restricted holiday is of type optional, like the calendar's restricted holidays.
     */
    public function asHoliday(): Holiday
    {
        return new Holiday([
            'date' => $this->date?->copy(), 'name' => $this->name,
            'type' => $this->is_restricted ? 'optional' : ($this->holiday_type ?: 'company'), 'is_half_day' => false,
        ]);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('status'), 'active');
    }

    public function scopeOnDay(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate($query->getModel()->qualifyColumn('date'), Carbon::parse($date)->toDateString());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }

    public function libraryEntry(): BelongsTo
    {
        return $this->belongsTo(HolidayLibraryEntry::class, 'holiday_library_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }
}
