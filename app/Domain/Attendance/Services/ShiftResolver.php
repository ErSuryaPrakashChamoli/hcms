<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Attendance\Models\WorkScheduleRule;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which shift does this employee work on this date? A day-level override first (HP-1 roster or an
 * approved shift change), then the individual assignment, then the highest-priority schedule rule.
 * Returns ['schedule' => ?WorkSchedule, 'shift' => ?Shift, 'weekly_off' => bool]. No schedule at all
 * -> shift null and weekly_off false ("no shift"); an override with no shift is a weekly off.
 * HROne parity TX-5: a rostered day whose override allows automatic shift correction also carries 'auto_shift' => true
 * (the key is absent otherwise, so every other answer is exactly as before); resolveBetween() gives the same answers for
 * several employees and a range of days in a few reads (the roster board).
 */
final class ShiftResolver
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
    ) {}

    /** @return array{schedule: ?WorkSchedule, shift: ?Shift, weekly_off: bool, anchor: ?Carbon, override?: ShiftOverride, auto_shift?: true} */
    public function resolve(Employee $employee, Carbon $date): array
    {
        [$schedule, $anchor] = $this->scheduleFor($employee, $date);

        $override = ShiftOverride::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->first();
        if ($override !== null) {
            $shift = $override->shift_id ? Shift::query()->find($override->shift_id) : null;

            return ['schedule' => $schedule, 'shift' => $shift, 'weekly_off' => $shift === null, 'anchor' => $anchor, 'override' => $override]
                + ($override->allowsAutoShift() ? ['auto_shift' => true] : []);
        }

        if ($schedule === null) {
            return ['schedule' => null, 'shift' => null, 'weekly_off' => false, 'anchor' => null];
        }

        $shiftId = $schedule->shiftIdFor($date, $anchor);
        $shift = $shiftId ? Shift::query()->find($shiftId) : null;

        return ['schedule' => $schedule, 'shift' => $shift, 'weekly_off' => $shiftId === null, 'anchor' => $anchor];
    }

    /** @return array{0: ?WorkSchedule, 1: ?Carbon} */
    public function scheduleFor(Employee $employee, Carbon $date): array
    {
        $assignment = WorkScheduleAssignment::query()
            ->with('schedule')
            ->where('employee_id', $employee->id)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();

        if ($assignment && $assignment->schedule?->status->value === 'active') {
            return [$assignment->schedule, $assignment->effective_from];
        }

        $context = $this->context->build($employee, $date);

        $rule = WorkScheduleRule::query()
            ->with('schedule')
            ->where('status', 'active')
            ->effectiveOn($date)
            ->orderBy('priority')->orderBy('id')
            ->get()
            ->first(fn (WorkScheduleRule $r) => $this->rules->matches($r->conditions ?? [], $context, 'all'));

        return $rule?->schedule ? [$rule->schedule, $rule->schedule->effective_from ?? $rule->effective_from] : [null, null];
    }

    /**
     * HROne parity TX-5: resolve() for every day from $from to $to of several employees, in a few reads — the rostered
     * days, the schedule assignments and the shifts once, the schedule rules (and each employee's positions) only when a
     * day falls back to them, and the rule context once per position and tenure month. Each answer is exactly resolve()'s
     * for that day (same precedence, same anchor, same keys), read in the caller's access scope like resolve().
     *
     * @param  iterable<Employee>  $employees
     * @return array<int, array<string, array{schedule: ?WorkSchedule, shift: ?Shift, weekly_off: bool, anchor: ?Carbon, override?: ShiftOverride, auto_shift?: true}>> employee id => Y-m-d => answer
     */
    public function resolveBetween(iterable $employees, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        /** @var Collection<int, Employee> $employees */
        $employees = collect($employees)->keyBy(fn (Employee $e) => (int) $e->id);
        $from = Carbon::parse(Carbon::parse($from)->toDateString());
        $to = Carbon::parse(Carbon::parse($to)->toDateString());
        if ($employees->isEmpty() || $to->lt($from)) {
            return [];
        }
        $ids = $employees->keys()->all();
        $overlaps = fn ($q) => $q->where(fn ($w) => $w->whereNull('effective_from')->orWhere('effective_from', '<=', $to->toDateString().' 23:59:59'))
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()));

        $overrides = ShiftOverride::query()->whereIn('employee_id', $ids)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->orderByDesc('id')->get()->groupBy(fn (ShiftOverride $o) => (int) $o->employee_id)
            ->map(fn (Collection $rows) => $rows->keyBy(fn (ShiftOverride $o) => $o->date->toDateString()));
        $assignments = $overlaps(WorkScheduleAssignment::query()->with('schedule')->whereIn('employee_id', $ids))
            ->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy(fn (WorkScheduleAssignment $a) => (int) $a->employee_id);
        $shifts = Shift::query()->get()->keyBy(fn (Shift $s) => (int) $s->id);
        $rules = null;
        $positions = null;

        $out = [];
        foreach ($employees as $id => $employee) {
            $contexts = [];
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $date = $day->toDateString();
                // scheduleFor(): the latest assignment effective that day when its schedule is active, else the first matching rule.
                $assignment = ($assignments[$id] ?? collect())->first(fn (WorkScheduleAssignment $a) => $a->isEffectiveOn($day));
                if ($assignment && $assignment->schedule?->status->value === 'active') {
                    [$schedule, $anchor] = [$assignment->schedule, $assignment->effective_from];
                } else {
                    $rules ??= $overlaps(WorkScheduleRule::query()->with('schedule')->where('status', 'active'))->orderBy('priority')->orderBy('id')->get();
                    $positions ??= EmployeePosition::query()->whereIn('employee_id', $ids)->orderByDesc('effective_from')->orderByDesc('id')->get()
                        ->groupBy(fn (EmployeePosition $p) => (int) $p->employee_id);
                    $position = ($positions[$id] ?? collect())->first(fn (EmployeePosition $p) => $p->isEffectiveOn($day));
                    $key = ($position?->id ?? 0).'|'.($employee->joining_date ? (int) $employee->joining_date->diffInMonths($day) : '-');
                    if (! array_key_exists($key, $contexts)) {
                        $employee->loadMissing('person');
                        $contexts[$key] = $this->context->build($employee, $day->copy());
                    }
                    $rule = $rules->filter(fn (WorkScheduleRule $r) => $r->isEffectiveOn($day))
                        ->first(fn (WorkScheduleRule $r) => $this->rules->matches($r->conditions ?? [], $contexts[$key], 'all'));
                    [$schedule, $anchor] = $rule?->schedule ? [$rule->schedule, $rule->schedule->effective_from ?? $rule->effective_from] : [null, null];
                }

                $override = $overrides[$id][$date] ?? null;
                if ($override !== null) {
                    $shift = $override->shift_id ? ($shifts[(int) $override->shift_id] ?? null) : null;
                    $out[$id][$date] = ['schedule' => $schedule, 'shift' => $shift, 'weekly_off' => $shift === null, 'anchor' => $anchor, 'override' => $override]
                        + ($override->allowsAutoShift() ? ['auto_shift' => true] : []);
                } elseif ($schedule === null) {
                    $out[$id][$date] = ['schedule' => null, 'shift' => null, 'weekly_off' => false, 'anchor' => null];
                } else {
                    $shiftId = $schedule->shiftIdFor($day->copy(), $anchor);
                    $out[$id][$date] = ['schedule' => $schedule, 'shift' => $shiftId ? ($shifts[$shiftId] ?? null) : null, 'weekly_off' => $shiftId === null, 'anchor' => $anchor];
                }
            }
        }

        return $out;
    }
}
