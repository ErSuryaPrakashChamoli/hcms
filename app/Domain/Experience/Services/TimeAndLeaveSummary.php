<?php

namespace App\Domain\Experience\Services;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\LeaveYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The time-and-leave side panel of the attendance calendar: one employee's leave balance, and how many leave requests and
 * regularisations they raised in the month on screen, its financial year and till date (each with how many were approved,
 * are pending or were rejected / withdrawn). A past month shows both as on its last day: the balance then, and "till" that
 * month instead of till date.
 *
 * Read only. Today's balances are the stored rows of the current leave year (LeaveBalances keeps them in step with every
 * ledger entry and every pending request); a balance on a past day adds up the ledger entries of its leave year dated up to
 * that day (with nothing pending: what was pending then is not kept). Opening the page never writes. A leave request counts in a period when any of its
 * days falls in it, and its approved days count only the days inside; a regularisation counts by the day it corrects.
 * The model queries keep their tenant and access scopes.
 */
final class TimeAndLeaveSummary
{
    public const PERIODS = ['month', 'year', 'all'];

    public function __construct(private readonly LeaveEntitlements $entitlements, private readonly LeaveYear $leaveYear) {}

    /**
     * Paid leave types the employee is entitled to (or holds a balance of), with what is left in the leave year of $on
     * (today when null or not past) as on that day.
     *
     * @return list<array{code: string, name: string, available: float, used: float, pending: float}>
     */
    public function balances(Employee $employee, ?Carbon $on = null): array
    {
        $past = $on !== null && $on->lt(today());
        $period = $this->leaveYear->periodFor($past ? $on : today());
        $rows = $past ? $this->balancesOn($employee, $period, $on)
            : LeaveBalance::query()->where('employee_id', $employee->id)->where('period_year', $period)->get()->keyBy('leave_type_id')
                ->map(fn (LeaveBalance $b) => ['available' => $b->available(), 'used' => (float) $b->used, 'pending' => (float) $b->pending]);
        $codes = array_keys($this->entitlements->for($employee));

        return LeaveType::query()->where('status', 'active')->where('category', '!=', 'unpaid')->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (LeaveType $type) => in_array($type->code, $codes, true) || $rows->has($type->id))
            ->map(fn (LeaveType $type) => [
                'code' => (string) $type->code,
                'name' => (string) $type->name,
                'available' => round((float) ($rows->get($type->id)['available'] ?? 0), 2),
                'used' => round((float) ($rows->get($type->id)['used'] ?? 0), 2),
                'pending' => round((float) ($rows->get($type->id)['pending'] ?? 0), 2),
            ])->values()->all();
    }

    /**
     * The balance of each leave type on a past day, from the ledger as LeaveBalances::recompute adds it up (closing is
     * the sum of every signed entry; used is usage net of reversals).
     *
     * @return Collection<int, array{available: float, used: float, pending: float}>
     */
    private function balancesOn(Employee $employee, int $period, Carbon $on): Collection
    {
        return LeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('period_year', $period)->whereDate('entry_date', '<=', $on->toDateString())
            ->selectRaw('leave_type_id, type, SUM(days) as total')->groupBy('leave_type_id', 'type')->get()
            ->groupBy('leave_type_id')
            ->map(fn (Collection $sums) => [
                'available' => (float) $sums->sum('total'),
                'used' => -(float) $sums->whereIn('type', ['usage', 'reversal'])->sum('total'),
                'pending' => 0.0,
            ]);
    }

    /**
     * @return array{periods: array{month: string, year: string, all: string}, leave: array<string, array<string, int|float>>, regularisation: array<string, array<string, int>>}
     */
    public function requests(Employee $employee, Carbon $month): array
    {
        $fy = FinancialYear::make();
        $end = $month->copy()->endOfMonth();
        $past = $end->lt(today());
        $windows = [
            'month' => [$month->copy()->startOfMonth(), $end],
            'year' => [$fy->start($month), $fy->end($month)->startOfDay()],
            // A past month: everything up to its end (as on that month); otherwise everything.
            'all' => $past ? [Carbon::create(1, 1, 1), $end] : null,
        ];
        $leave = LeaveRequest::query()->where('employee_id', $employee->id)->get(['id', 'status', 'from_date', 'to_date', 'days', 'dates']);
        $regularisations = AttendanceRegularisation::query()->where('employee_id', $employee->id)->get(['id', 'status', 'date']);

        $out = ['periods' => ['month' => $month->format('M Y'), 'year' => 'FY '.$fy->label($month), 'all' => $past ? 'Till '.$month->format('M Y') : 'Till date'], 'leave' => [], 'regularisation' => []];
        foreach ($windows as $key => $window) {
            $out['leave'][$key] = $this->leaveCounts($leave, $window);
            $out['regularisation'][$key] = $this->regularisationCounts($regularisations, $window);
        }

        return $out;
    }

    /**
     * @param  Collection<int, LeaveRequest>  $requests
     * @param  array{0: Carbon, 1: Carbon}|null  $window
     * @return array{applied: int, approved: int, pending: int, rejected: int, withdrawn: int, days: float}
     */
    private function leaveCounts(Collection $requests, ?array $window): array
    {
        $in = $window === null ? $requests : $requests->filter(fn (LeaveRequest $r) => $r->from_date->lte($window[1]) && $r->to_date->gte($window[0]));
        $taken = $in->filter(fn (LeaveRequest $r) => in_array($r->status, LeaveRequest::TAKEN, true));

        return [
            'applied' => $in->count(),
            'approved' => $taken->count(),
            'pending' => $in->where('status', 'pending')->count(),
            'rejected' => $in->where('status', 'rejected')->count(),
            'withdrawn' => $in->where('status', 'cancelled')->count(),
            'days' => round((float) $taken->sum(fn (LeaveRequest $r) => $this->daysWithin($r, $window)), 2),
        ];
    }

    /** @param  array{0: Carbon, 1: Carbon}|null  $window */
    private function daysWithin(LeaveRequest $request, ?array $window): float
    {
        if ($window === null || ($request->from_date->gte($window[0]) && $request->to_date->lte($window[1])) || ! is_array($request->dates)) {
            return (float) $request->days;
        }

        return (float) collect($request->dates)->filter(fn ($d) => Carbon::parse($d['date'])->betweenIncluded($window[0], $window[1]))->sum(fn ($d) => (float) $d['days']);
    }

    /**
     * @param  Collection<int, AttendanceRegularisation>  $regularisations
     * @param  array{0: Carbon, 1: Carbon}|null  $window
     * @return array{applied: int, approved: int, pending: int, rejected: int, withdrawn: int}
     */
    private function regularisationCounts(Collection $regularisations, ?array $window): array
    {
        $in = $window === null ? $regularisations : $regularisations->filter(fn (AttendanceRegularisation $r) => $r->date->betweenIncluded($window[0], $window[1]));

        return [
            'applied' => $in->count(),
            'approved' => $in->where('status', 'approved')->count(),
            'pending' => $in->where('status', 'pending')->count(),
            'rejected' => $in->where('status', 'rejected')->count(),
            'withdrawn' => $in->where('status', 'cancelled')->count(),
        ];
    }
}
