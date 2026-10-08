<?php

namespace App\Domain\Career\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;

/**
 * Phase 9 career movement history, read from the one employment history (employee_positions with
 * their change type: hire, promotion, transfer, demotion, reassignment). No second history is kept.
 */
final class CareerMovements
{
    /** @return list<array{effective_from: string, effective_to: ?string, change_type: string, designation: ?string, department: ?string, location: ?string, reason: ?string}> */
    public function for(Employee $employee): array
    {
        return EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->with(['designation', 'department', 'location'])
            ->where('employee_id', $employee->id)->orderBy('effective_from')->orderBy('id')->get()
            ->map(fn (EmployeePosition $p) => [
                'effective_from' => $p->effective_from?->toDateString(), 'effective_to' => $p->effective_to?->toDateString(), 'change_type' => (string) $p->change_type,
                'designation' => $p->designation?->name, 'department' => $p->department?->name, 'location' => $p->location?->name, 'reason' => $p->reason,
            ])->values()->all();
    }
}
