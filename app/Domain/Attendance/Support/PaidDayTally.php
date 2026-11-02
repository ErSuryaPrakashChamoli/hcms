<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\AttendanceRecord;

/**
 * HROne parity TX-4: the day counts paid days are made of, from processed attendance days — quantities only.
 *
 * - paid_days is exactly AttendanceOutput::summary()'s figure (paid days minus half a day for every half day), so the
 *   paid-days register and the summary always agree.
 * - The categories split the month the way HROne's "Paid days" card does: present (present, work from home, on duty, field
 *   duty and the worked half of a half day), paid leave, holidays and weekly offs — the four categories an adjustment can
 *   set or reduce — and, not paid: unpaid leave, absent (and the absent half of a half day), unpaid weekly offs.
 * - Late-mark penalty days stay a separate quantity (penalty_days), as in the summary.
 * - HROne parity TX-6: a day HR set by half (adjustments 'half_statuses', no half-day leave) counts each half in its own
 *   category (a worked half as present, a weekly off or holiday half as such, an absent half as absent), half a day each —
 *   the halves add up to the day's paid days because the day's status is worked out from the same halves.
 */
final class PaidDayTally
{
    /** Attendance policy rounding for partial reductions (setting paid_days_round_off; blank = two decimals). */
    public const ROUNDING = [
        'none' => 'No rounding (two decimals)',
        'nearest_half' => 'Nearest half day',
        'down_half' => 'Down to a half day',
        'up_half' => 'Up to a half day',
        'nearest_whole' => 'Nearest whole day',
        'down_whole' => 'Down to a whole day',
        'up_whole' => 'Up to a whole day',
    ];

    private const PRESENT = ['present', 'wfh', 'on_duty', 'field_duty', 'half_day'];

    /**
     * @param  iterable<AttendanceDay>  $days
     * @return array{days: int, present: float, paid_leave: float, holiday: float, weekly_off: float, unpaid_leave: float, absent: float, unpaid_weekly_off: float, half_days: int, other: int, paid_days: float, penalty_days: float}
     */
    public static function fromDays(iterable $days): array
    {
        $t = ['days' => 0, 'present' => 0.0, 'paid_leave' => 0.0, 'holiday' => 0.0, 'weekly_off' => 0.0, 'unpaid_leave' => 0.0, 'absent' => 0.0,
            'unpaid_weekly_off' => 0.0, 'half_days' => 0, 'other' => 0, 'paid_days' => 0.0, 'penalty_days' => 0.0];
        $paid = 0;
        $halves = 0;
        foreach ($days as $day) {
            $t['days']++;
            $paid += $day->isPaidDay ? 1 : 0;
            $halves += $day->isHalfDay ? 1 : 0;
            $t['half_days'] += $day->isHalfDay ? 1 : 0;
            $t['penalty_days'] += $day->penaltyDays;
            $share = $day->isHalfDay ? 0.5 : 1.0;
            $halfDayLeave = ! empty($day->adjustments['half_day_leave']);
            $byHalf = $halfDayLeave ? null : ($day->adjustments['half_statuses'] ?? null);
            if (is_array($byHalf) && isset($byHalf['first_half'], $byHalf['second_half'])) {
                foreach ([$byHalf['first_half'], $byHalf['second_half']] as $half) {
                    match (true) {
                        in_array($half, self::PRESENT, true) => $t['present'] += 0.5,
                        $half === 'holiday' => $t['holiday'] += 0.5,
                        $half === 'weekly_off' => $t['weekly_off'] += 0.5,
                        default => $t['absent'] += 0.5,
                    };
                }

                continue;
            }
            match (true) {
                in_array($day->status, self::PRESENT, true) => $t['present'] += $share,
                $day->status === 'leave' => $t['paid_leave'] += $share,
                $day->status === 'holiday' => $t['holiday'] += $share,
                $day->status === 'weekly_off' && $day->isPaidDay => $t['weekly_off'] += $share,
                $day->status === 'weekly_off' => $t['unpaid_weekly_off'] += 1.0,
                $day->status === 'unpaid_leave' => $t['unpaid_leave'] += $share,
                $day->status === 'absent' => $t['absent'] += $share,
                default => $t['other']++,
            };
            // The other half of a half day: absent unless half-day leave covers it.
            if (($day->status === 'half_day' && ! $halfDayLeave) || (in_array($day->status, ['leave', 'unpaid_leave'], true) && $halfDayLeave)) {
                $t['absent'] += 0.5;
            }
        }
        // The summary's own reading: a paid status counts a day, a half day takes half a day off.
        $t['paid_days'] = $paid - $halves * 0.5;

        foreach (['present', 'paid_leave', 'holiday', 'weekly_off', 'unpaid_leave', 'absent', 'unpaid_weekly_off', 'paid_days', 'penalty_days'] as $key) {
            $t[$key] = round((float) $t[$key], 2);
        }

        return $t;
    }

    /**
     * Minutes worked by kind of day: holidays (a holiday name on the day), weekly offs (a weekly off, or work on an off day
     * without a holiday) and working days (the rest); plus approved overtime.
     *
     * @param  iterable<AttendanceRecord>  $records
     * @return array{working_minutes: int, weekly_off_minutes: int, holiday_minutes: int, overtime_minutes: int}
     */
    public static function minutes(iterable $records): array
    {
        $out = ['working_minutes' => 0, 'weekly_off_minutes' => 0, 'holiday_minutes' => 0, 'overtime_minutes' => 0];
        foreach ($records as $record) {
            $worked = (int) $record->worked_minutes;
            $kind = match (true) {
                filled($record->holiday_name) || $record->status === 'holiday' => 'holiday_minutes',
                $record->status === 'weekly_off' || in_array('holiday_work', (array) ($record->exceptions ?? []), true) => 'weekly_off_minutes',
                default => 'working_minutes',
            };
            $out[$kind] += $worked;
            $out['overtime_minutes'] += (int) $record->overtime_approved_minutes;
        }

        return $out;
    }

    /** A quantity of days rounded as the attendance policy says (blank or unknown: two decimals). */
    public static function round(float $days, ?string $mode): float
    {
        $halves = round($days * 2, 6);
        $wholes = round($days, 6);

        return match ((string) $mode) {
            'nearest_half' => round($halves) / 2,
            'down_half' => floor($halves) / 2,
            'up_half' => ceil($halves) / 2,
            'nearest_whole' => (float) round($wholes),
            'down_whole' => (float) floor($wholes),
            'up_whole' => (float) ceil($wholes),
            default => round($days, 2),
        };
    }

    /** "1.5 h" style hours from minutes. */
    public static function hours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }
}
