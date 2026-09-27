<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Promotion foundation: a new effective-dated position (designation / level / grade / department…)
 * and, optionally, a new line manager, in one transaction. Compensation changes stay with the
 * Payroll domain (Salaries::assign) and are not part of this action (Phase 1 §30, §33).
 */
final class PromoteEmployeeAction
{
    public function __construct(private readonly AssignPositionAction $positions, private readonly ChangeManagerAction $managers) {}

    /** @param  array<string, mixed>  $dimensions */
    public function handle(Employee $employee, array $dimensions, CarbonInterface|string|null $effectiveFrom = null, ?int $managerId = null, ?string $reason = null): EmployeePosition
    {
        return DB::transaction(function () use ($employee, $dimensions, $effectiveFrom, $managerId, $reason) {
            $position = $this->positions->handle($employee, $dimensions, 'promotion', $effectiveFrom, $reason);

            if ($managerId !== null) {
                $this->managers->handle($employee, Employee::query()->findOrFail($managerId), 'line', $effectiveFrom, $reason);
            }

            return $position;
        });
    }
}
