<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Transfer: one atomic transaction that opens a new effective-dated position with only the
 * changed dimensions and, optionally, a new line manager (contract §3, Phase 1 §31).
 */
final class TransferEmployeeAction
{
    public function __construct(private readonly AssignPositionAction $positions, private readonly ChangeManagerAction $managers) {}

    /** @param  array<string, mixed>  $dimensions  company_id, location_id, business_unit_id, division_id, department_id, team_id, designation_id, work_mode_id … */
    public function handle(Employee $employee, array $dimensions, CarbonInterface|string|null $effectiveFrom = null, ?int $managerId = null, ?string $reason = null): EmployeePosition
    {
        return DB::transaction(function () use ($employee, $dimensions, $effectiveFrom, $managerId, $reason) {
            $position = $this->positions->handle($employee, $dimensions, 'transfer', $effectiveFrom, $reason);

            if ($managerId !== null) {
                $this->managers->handle($employee, Employee::query()->findOrFail($managerId), 'line', $effectiveFrom, $reason);
            }

            return $position;
        });
    }
}
