<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HROne parity TX-4 ("Override paid days", owner decision TIM-2 / D-TX2): HR's change to one employee's paid days for one
 * attendance month (company, year, month). Written only by PaidDayAdjustments; counted by AttendanceOutput only while
 * attendance.paid_day_adjustments.enabled is on. Never deleted: reversing it (or saving a newer one for the same month)
 * marks it reversed with who, when and why, and `active` becomes null.
 *
 * - mode `set`: `categories` holds the counts HR fixed (present, paid_leave, holiday, weekly_off); a category left out
 *   keeps the processed count; with no categories, `value` is the paid days set.
 * - mode `reduce`: `categories` holds, per category, how much is taken away: none, partial (a percentage, rounded as the
 *   attendance policy says) or full.
 */
#[Fillable(['tenant_id', 'employee_id', 'company_id', 'year', 'month', 'start_date', 'end_date', 'mode', 'value', 'categories', 'paid_days_before', 'paid_days_after', 'rounding', 'reason', 'status', 'active', 'created_by', 'reversed_by', 'reversed_at', 'reversal_reason'])]
class PaidDayAdjustment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $table = 'attendance_paid_day_adjustments';

    public const MODES = ['set' => 'Set the counts', 'reduce' => 'Reduce chosen days'];

    /** The day categories paid days are made of (HROne: present days, paid leave, holidays, week offs). */
    public const CATEGORIES = ['present' => 'Present days', 'paid_leave' => 'Paid leave', 'holiday' => 'Holidays', 'weekly_off' => 'Weekly offs'];

    public const HOW = ['none' => 'No reduction', 'partial' => 'Partial reduction', 'full' => 'Full reduction'];

    public const STATUSES = ['active' => 'Counting', 'reversed' => 'Reversed'];

    protected $attributes = ['status' => 'active', 'active' => true];

    protected function casts(): array
    {
        return [
            'year' => 'integer', 'month' => 'integer', 'start_date' => 'date', 'end_date' => 'date', 'value' => 'decimal:2', 'categories' => 'array',
            'paid_days_before' => 'decimal:2', 'paid_days_after' => 'decimal:2', 'active' => 'boolean', 'reversed_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Paid-day adjustment '.$this->label();
    }

    public function label(): string
    {
        return Carbon::create((int) $this->year, (int) $this->month, 1)->format('M Y');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** One line saying what the adjustment does ("Present days 18, Paid leave 2 …" or "Present days: 10% …"). */
    public function describe(): string
    {
        $categories = (array) ($this->categories ?? []);
        if ($this->mode === 'set') {
            $parts = [];
            foreach (self::CATEGORIES as $key => $label) {
                if (array_key_exists($key, $categories) && $categories[$key] !== null && $categories[$key] !== '') {
                    $parts[] = $label.' '.self::number((float) $categories[$key]);
                }
            }

            return $parts === [] ? 'Paid days set to '.self::number((float) $this->value) : 'Set: '.implode(', ', $parts);
        }
        $parts = [];
        foreach (self::CATEGORIES as $key => $label) {
            $how = (string) ($categories[$key]['how'] ?? 'none');
            if ($how === 'full') {
                $parts[] = $label.': all';
            } elseif ($how === 'partial') {
                $parts[] = $label.': '.self::number((float) ($categories[$key]['percent'] ?? 0)).'%';
            }
        }

        return 'Reduce: '.($parts === [] ? 'nothing' : implode(', ', $parts));
    }

    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
