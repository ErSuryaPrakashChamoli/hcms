<?php

namespace App\Domain\Leave\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * HROne parity RQ-4 (matrix 20 §20.1.18 Leave #10, "View Team leaves"): who else in the team is away on the dates chosen in
 * the leave form, before applying.
 *
 * The team is the people with a current reporting relationship to the employee's line manager, and the employee's own
 * direct reports (the employee left out). What is listed follows the leave calendar's rules exactly: only a viewer who
 * may open the leave calendar (leave.view or leave.approve) sees anything, and only leave inside their access scope (the
 * query layer narrows every read, as on the calendar); approved leave, leave whose cancellation is waiting, and pending
 * leave. A private leave the viewer may not see in full reads "Away" (LeavePrivacy). Reasons are never shown. Read only.
 */
final class TeamAway
{
    /** At most this many rows. */
    public const LIMIT = 20;

    public function __construct(private readonly LeavePrivacy $privacy) {}

    /** The leave calendar's access rule (LeaveCalendar::canAccess). */
    public static function mayView(?User $viewer): bool
    {
        return $viewer !== null && ($viewer->hasPermission('leave.view') || $viewer->hasPermission('leave.approve'));
    }

    /** @return list<int> the employee's team today (see the class note) */
    public function team(Employee $employee): array
    {
        $managerId = ReportingRelationship::query()->where('employee_id', $employee->getKey())->where('is_primary', true)
            ->currentlyEffective()->orderByDesc('effective_from')->orderByDesc('id')->value('manager_id');
        $peers = $managerId === null ? collect() : ReportingRelationship::query()->where('manager_id', $managerId)->currentlyEffective()->pluck('employee_id');
        $reports = ReportingRelationship::query()->where('manager_id', $employee->getKey())->currentlyEffective()->pluck('employee_id');

        return $peers->concat($reports)->map(fn ($id) => (int) $id)->reject(fn (int $id) => $id === (int) $employee->getKey())->unique()->values()->all();
    }

    /**
     * @return list<array{employee_id: int, name: string, label: string, from: Carbon, to: Carbon, pending: bool}>
     */
    public function for(?User $viewer, Employee $employee, CarbonInterface|string|null $from, CarbonInterface|string|null $to): array
    {
        if (! self::mayView($viewer) || ! $employee->exists || blank($from)) {
            return [];
        }
        $from = Carbon::parse($from)->startOfDay();
        $to = blank($to) ? $from->copy() : Carbon::parse($to)->startOfDay();
        if ($to->lt($from) || ($team = $this->team($employee)) === []) {
            return [];
        }

        return LeaveRequest::query()->with(['employee.person', 'leaveType'])->whereIn('employee_id', $team)
            ->whereIn('status', [...LeaveRequest::TAKEN, 'pending'])
            ->whereDate('from_date', '<=', $to->toDateString())->whereDate('to_date', '>=', $from->toDateString())
            ->orderBy('from_date')->orderBy('id')->limit(self::LIMIT)->get()
            ->map(fn (LeaveRequest $r) => [
                'employee_id' => (int) $r->employee_id,
                'name' => (string) ($r->employee?->display_name ?? $r->employee?->employee_code ?? ''),
                'label' => $this->privacy->typeLabel($r, $viewer),
                'from' => $r->from_date->copy(), 'to' => $r->to_date->copy(),
                'pending' => $r->status === 'pending',
            ])->values()->all();
    }
}
