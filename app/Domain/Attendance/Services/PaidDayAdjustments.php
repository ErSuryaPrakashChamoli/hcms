<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\AttendanceCycle;
use App\Domain\Attendance\Contracts\PaidDayAdjustmentReader;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\PaidDayAdjustment;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Attendance\Support\PaidDayTally;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HROne parity TX-4 ("Override paid days", matrix 24 §24.5.3 #18–21, §24.5.4 #6; owner decision TIM-2 / D-TX2): HR changes
 * an employee's paid days for one attendance month, as a quantity of days.
 *
 * - Off by default (attendance.paid_day_adjustments.enabled): nothing is offered or saved, and rows left behind have no
 *   effect — AttendanceOutput returns exactly what it returned before.
 * - Mode "set" fixes the counts of present days, paid leave, holidays and weekly offs (the paid days are their sum); mode
 *   "reduce" takes, per category, nothing, a percentage (rounded as the attendance policy's paid_days_round_off says) or
 *   everything. Paid days = present days + paid leave + holidays + weekly offs, after the reductions chosen; late-mark
 *   penalty days stay a separate quantity.
 * - One counting adjustment per employee and month: saving a new one reverses the earlier one. Nothing is deleted;
 *   reversing keeps who, when and why. A reason is always required, and every save and reversal is audited.
 * - Refused on a month whose days are locked for good (finalized downstream, or a locked day in it) and, under the month
 *   close rule, on a closed month unless corrections after lock are allowed (AttendanceMonthCloses::assertOpen).
 * - attendance.override (TX-2; attendance.manage still grants it), inside the actor's access scope; never on one's own
 *   attendance.
 */
final class PaidDayAdjustments implements PaidDayAdjustmentReader
{
    public const SETTING = 'attendance.paid_day_adjustments.enabled';

    /** HROne's "Override paid days" is an override of the month's attendance: TX-2's attendance.override (attendance.manage still grants it). */
    public const PERMISSION = 'attendance.override';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AttendanceCycle $cycle,
        private readonly AttendanceMonthCloses $closes,
        private readonly AttendanceMonthPopulation $population,
        private readonly PolicyResolver $policies,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING, false);
    }

    public function inUse(): bool
    {
        return $this->enabled();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function window(int $companyId, int $year, int $month): array
    {
        return $this->cycle->window($companyId, $year, $month);
    }

    /** The counting adjustment of an employee's month (null when none). */
    public function active(int $employeeId, int $companyId, int $year, int $month): ?PaidDayAdjustment
    {
        return PaidDayAdjustment::query()->where('employee_id', $employeeId)->where('company_id', $companyId)
            ->where('year', $year)->where('month', $month)->where('status', 'active')->latest('id')->first();
    }

    // --- Reading the effect --------------------------------------------------------------------------------------------

    /**
     * The processed counts of an employee's days from $start to $end (as AttendanceOutput reads them).
     *
     * @return array<string, mixed>
     */
    public function counts(Employee $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        return PaidDayTally::fromDays(app(AttendanceOutput::class)->forEmployee($employee, Carbon::parse($start->toDateString()), Carbon::parse($end->toDateString())));
    }

    /**
     * What an adjustment does to a month's counts. Pure: the same counts and input always give the same answer.
     *
     * @param  array<string, mixed>  $counts  PaidDayTally::fromDays()
     * @param  array<string, mixed>  $categories
     * @return array{before: float, after: float, change: float, lines: array<string, array{label: string, count: float, after: float, note: string}>}
     */
    public function effect(array $counts, string $mode, array $categories, ?float $value, ?string $rounding): array
    {
        $before = round((float) $counts['paid_days'], 2);
        $lines = [];
        if ($mode === 'set') {
            $given = array_filter(array_intersect_key($categories, PaidDayAdjustment::CATEGORIES), fn ($v) => $v !== null && $v !== '');
            foreach (PaidDayAdjustment::CATEGORIES as $key => $label) {
                $count = (float) $counts[$key];
                $set = array_key_exists($key, $given) ? round(max(0.0, (float) $given[$key]), 2) : $count;
                $lines[$key] = ['label' => $label, 'count' => $count, 'after' => $set, 'note' => array_key_exists($key, $given) ? 'set to '.PaidDayAdjustment::number($set) : 'as processed'];
            }
            $after = $given === [] && $value !== null ? round(max(0.0, $value), 2) : round(array_sum(array_column($lines, 'after')), 2);
            if ($given === [] && $value !== null) {
                $lines = [];
            }
        } else {
            $taken = 0.0;
            foreach (PaidDayAdjustment::CATEGORIES as $key => $label) {
                $count = (float) $counts[$key];
                $how = (string) ($categories[$key]['how'] ?? 'none');
                $percent = (float) ($categories[$key]['percent'] ?? 0);
                $reduction = match ($how) {
                    'full' => $count,
                    'partial' => min($count, max(0.0, PaidDayTally::round($count * $percent / 100, $rounding))),
                    default => 0.0,
                };
                $taken += $reduction;
                $lines[$key] = ['label' => $label, 'count' => $count, 'after' => round($count - $reduction, 2),
                    'note' => match ($how) {
                        'full' => 'full reduction',
                        'partial' => PaidDayAdjustment::number($percent).'% reduction ('.PaidDayAdjustment::number($reduction).')',
                        default => 'no reduction',
                    }];
            }
            $after = round(max(0.0, $before - $taken), 2);
        }

        return ['before' => $before, 'after' => $after, 'change' => round($after - $before, 2), 'lines' => $lines];
    }

    /** The change in paid days an adjustment makes to the given days of its month (the days outside it are ignored). */
    public function changeOf(PaidDayAdjustment $adjustment, Collection $days): float
    {
        $start = $adjustment->start_date->toDateString();
        $end = $adjustment->end_date->toDateString();
        $inside = $days->filter(fn (AttendanceDay $d) => $d->date >= $start && $d->date <= $end);

        return $this->effect(PaidDayTally::fromDays($inside), (string) $adjustment->mode, (array) ($adjustment->categories ?? []),
            $adjustment->value !== null ? (float) $adjustment->value : null, $adjustment->rounding)['change'];
    }

    /**
     * For AttendanceOutput::summary(): the counting adjustments of the employee whose month lies inside [from, to], and the
     * change they make to the days given (null while switched off).
     *
     * @param  Collection<int, AttendanceDay>  $days
     */
    public function changeWithin(Employee $employee, string $from, string $to, Collection $days): ?float
    {
        if (! $this->enabled()) {
            return null;
        }
        $change = 0.0;
        foreach (PaidDayAdjustment::query()->where('employee_id', $employee->id)->where('status', 'active')
            ->whereDate('start_date', '>=', $from)->whereDate('end_date', '<=', $to)->orderBy('start_date')->get() as $adjustment) {
            $change += $this->changeOf($adjustment, $days);
        }

        return round($change, 2);
    }

    public function forMonth(int $employeeId, int $companyId, int $year, int $month): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $adjustment = $this->active($employeeId, $companyId, $year, $month);
        $employee = $adjustment?->employee()->withoutGlobalScope(AccessScope::class)->first();
        if ($adjustment === null || $employee === null) {
            return null;
        }
        $days = app(AttendanceOutput::class)->forEmployee($employee, Carbon::parse($adjustment->start_date->toDateString()), Carbon::parse($adjustment->end_date->toDateString()));
        $effect = $this->effect(PaidDayTally::fromDays($days), (string) $adjustment->mode, (array) ($adjustment->categories ?? []),
            $adjustment->value !== null ? (float) $adjustment->value : null, $adjustment->rounding);

        return [
            'id' => (int) $adjustment->id, 'mode' => (string) $adjustment->mode, 'label' => $adjustment->label(), 'described' => $adjustment->describe(),
            'reason' => (string) $adjustment->reason, 'paid_days_before' => $effect['before'], 'paid_days_after' => $effect['after'], 'change' => $effect['change'],
            'start' => $adjustment->start_date->toDateString(), 'end' => $adjustment->end_date->toDateString(),
        ];
    }

    // --- Preview, save and reverse -------------------------------------------------------------------------------------

    /** The attendance policy's rounding for partial reductions on the month's last day (null = two decimals). */
    public function rounding(Employee $employee, CarbonInterface $on): ?string
    {
        $mode = $this->policies->resolve('attendance', $employee, Carbon::parse($on->toDateString()))?->setting('paid_days_round_off');

        return is_string($mode) && array_key_exists($mode, PaidDayTally::ROUNDING) && $mode !== 'none' ? $mode : null;
    }

    /**
     * The input as the service keeps it (refused with a message when it cannot be used).
     *
     * @param  array<string, mixed>  $input  mode; set: categories.{key} counts (or value); reduce: categories.{key}.how / percent
     * @return array{mode: string, categories: array<string, mixed>, value: ?float}
     */
    public function normalise(array $input, int $span): array
    {
        $mode = (string) ($input['mode'] ?? '');
        if (! array_key_exists($mode, PaidDayAdjustment::MODES)) {
            throw new RuntimeException('Choose how to adjust the paid days: set the counts or reduce chosen days.');
        }
        $raw = (array) ($input['categories'] ?? []);
        $categories = [];
        if ($mode === 'set') {
            foreach (array_keys(PaidDayAdjustment::CATEGORIES) as $key) {
                $value = $raw[$key] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (! is_numeric($value) || (float) $value < 0 || (float) $value > $span) {
                    throw new RuntimeException(PaidDayAdjustment::CATEGORIES[$key].' must be a number of days from 0 to '.$span.'.');
                }
                $categories[$key] = round((float) $value, 2);
            }
            $value = isset($input['value']) && $input['value'] !== '' && $input['value'] !== null ? $input['value'] : null;
            if ($categories === [] && $value === null) {
                throw new RuntimeException('Give the number of days for at least one category.');
            }
            if ($categories === [] && (! is_numeric($value) || (float) $value < 0 || (float) $value > $span)) {
                throw new RuntimeException('Paid days must be a number of days from 0 to '.$span.'.');
            }
            if (array_sum($categories) > $span) {
                throw new RuntimeException('The counts add up to '.PaidDayAdjustment::number(array_sum($categories)).' days, more than the '.$span.' days of the month.');
            }

            return ['mode' => 'set', 'categories' => $categories, 'value' => $categories === [] ? round((float) $value, 2) : null];
        }

        foreach (array_keys(PaidDayAdjustment::CATEGORIES) as $key) {
            $how = (string) ($raw[$key]['how'] ?? 'none');
            if (! array_key_exists($how, PaidDayAdjustment::HOW)) {
                throw new RuntimeException('Choose no, partial or full reduction for '.mb_strtolower(PaidDayAdjustment::CATEGORIES[$key]).'.');
            }
            if ($how === 'partial') {
                $percent = $raw[$key]['percent'] ?? null;
                if (! is_numeric($percent) || (float) $percent <= 0 || (float) $percent > 100) {
                    throw new RuntimeException('Give the share of '.mb_strtolower(PaidDayAdjustment::CATEGORIES[$key]).' to reduce, from 0 to 100%.');
                }
                $categories[$key] = ['how' => 'partial', 'percent' => round((float) $percent, 2)];
            } elseif ($how === 'full') {
                $categories[$key] = ['how' => 'full'];
            }
        }
        if ($categories === []) {
            throw new RuntimeException('Choose at least one category to reduce.');
        }

        return ['mode' => 'reduce', 'categories' => $categories, 'value' => null];
    }

    /**
     * What saving the input would do for one employee's month, before anything is saved: the window, the processed counts,
     * each category before and after, paid days before and after, the adjustment it would replace and — when it would be
     * refused — why.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(Employee $employee, int $companyId, int $year, int $month, array $input, ?User $actor = null): array
    {
        $employee->loadMissing('person');
        [$start, $end] = $this->window($companyId, $year, $month);
        $span = (int) $start->diffInDays($end) + 1;
        $counts = $this->counts($employee, $start, $end);
        $rounding = $this->rounding($employee, $end);
        $label = Carbon::create($year, $month, 1)->format('M Y');
        $refusal = $this->refusal($employee, $companyId, $year, $month, $actor);
        try {
            $normalised = $this->normalise($input, $span);
            $effect = $this->effect($counts, $normalised['mode'], $normalised['categories'], $normalised['value'], $rounding);
        } catch (RuntimeException $e) {
            $normalised = null;
            $effect = null;
            $refusal ??= $e->getMessage();
        }

        return [
            'employee_id' => (int) $employee->id, 'employee' => trim($employee->employee_code.' · '.($employee->person?->display_name ?? ''), ' ·'),
            'label' => $label, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'span' => $span,
            'counts' => $counts, 'rounding' => $rounding, 'rounding_label' => PaidDayTally::ROUNDING[$rounding ?? 'none'],
            'input' => $normalised, 'effect' => $effect, 'replaces' => $this->active((int) $employee->id, $companyId, $year, $month), 'refusal' => $refusal,
        ];
    }

    /** Why the actor may not adjust this employee's month (null = may). */
    public function refusal(Employee $employee, int $companyId, int $year, int $month, ?User $actor = null): ?string
    {
        if (! $this->enabled()) {
            return 'Paid-day adjustments are switched off (Setup → Time office).';
        }
        if ($actor !== null) {
            if (! $actor->hasPermission(self::PERMISSION)) {
                return 'You cannot adjust paid days.';
            }
            if ($employee->user_id !== null && (int) $employee->user_id === (int) $actor->id) {
                return 'You cannot adjust your own paid days.';
            }
            if (! app(AccessScopes::class)->allows($actor, $employee)) {
                return 'This employee is not in your access.';
            }
        }

        return $this->lockRefusal($employee, $companyId, $year, $month);
    }

    /** Why the month cannot change any more (null = it can): finalized, a locked day, or closed under the month close rule. */
    public function lockRefusal(Employee $employee, int $companyId, int $year, int $month): ?string
    {
        $label = Carbon::create($year, $month, 1)->format('M Y');
        try {
            [$start, $end] = $this->window($companyId, $year, $month);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        if (! $this->population->query($companyId, $start, $end, false)->whereKey($employee->id)->exists()) {
            return 'The employee does not belong to this company in '.$label.'.';
        }
        if ($this->cycle->isFinal($companyId, $year, $month)) {
            return $label.' is finalized: its days are locked for good.';
        }
        $locked = AttendanceRecord::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('is_locked', true)
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->exists();
        if ($locked) {
            return 'Days of '.$label.' are locked for good: its paid days can no longer change.';
        }
        try {
            $this->closes->assertOpen($employee, $start, $end, $label.' is closed for attendance: reopen the month to adjust its paid days.');
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Save an adjustment for the employee's month (replacing the one counting until now). Audited with the reason.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(Employee $employee, int $companyId, int $year, int $month, array $input, string $reason, User $actor): PaidDayAdjustment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Give the reason for adjusting the paid days.');
        }
        $preview = $this->preview($employee, $companyId, $year, $month, $input, $actor);
        if ($preview['refusal'] !== null) {
            throw new RuntimeException($preview['refusal']);
        }
        $reason = mb_substr($reason, 0, 255);

        try {
            return DB::transaction(function () use ($employee, $companyId, $year, $month, $preview, $reason, $actor) {
                // Checked again inside the transaction: the month may have been finalized or closed meanwhile.
                if (($refusal = $this->lockRefusal($employee, $companyId, $year, $month)) !== null) {
                    throw new RuntimeException($refusal);
                }
                $current = PaidDayAdjustment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('company_id', $companyId)
                    ->where('year', $year)->where('month', $month)->where('status', 'active')->lockForUpdate()->first();
                if ($current !== null) {
                    $current->fill(['status' => 'reversed', 'active' => null, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => mb_substr('Replaced by a newer adjustment: '.$reason, 0, 255)])
                        ->withAuditReason('Replaced by a newer adjustment: '.$reason)->save();
                }
                $adjustment = new PaidDayAdjustment([
                    'employee_id' => $employee->id, 'company_id' => $companyId, 'year' => $year, 'month' => $month,
                    'start_date' => $preview['start'], 'end_date' => $preview['end'], 'mode' => $preview['input']['mode'],
                    'value' => $preview['input']['mode'] === 'set' ? $preview['effect']['after'] : null, 'categories' => $preview['input']['categories'] ?: null,
                    'paid_days_before' => $preview['effect']['before'], 'paid_days_after' => $preview['effect']['after'], 'rounding' => $preview['rounding'],
                    'reason' => $reason, 'status' => 'active', 'active' => true, 'created_by' => $actor->id,
                ]);
                $adjustment->withAuditReason($reason)->save();

                return $adjustment;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('Someone else adjusted this month at the same moment; refresh and look again.');
        }
    }

    /** Reverse a counting adjustment (the month's paid days go back to the processed figure). Audited with the reason. */
    public function reverse(PaidDayAdjustment $adjustment, User $actor, string $reason): PaidDayAdjustment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Give the reason for reversing the adjustment.');
        }
        $employee = $adjustment->employee()->withoutGlobalScope(AccessScope::class)->first();
        if ($employee === null) {
            throw new RuntimeException('The employee of this adjustment no longer exists.');
        }
        if (($refusal = $this->refusal($employee, (int) $adjustment->company_id, (int) $adjustment->year, (int) $adjustment->month, $actor)) !== null) {
            throw new RuntimeException($refusal);
        }

        return DB::transaction(function () use ($adjustment, $actor, $reason) {
            $row = PaidDayAdjustment::query()->withoutGlobalScope(AccessScope::class)->whereKey($adjustment->id)->lockForUpdate()->first();
            if ($row === null || ! $row->isActive()) {
                throw new RuntimeException('This adjustment is no longer counting.');
            }
            $row->fill(['status' => 'reversed', 'active' => null, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => mb_substr($reason, 0, 255)])
                ->withAuditReason($reason)->save();

            return $row;
        });
    }
}
