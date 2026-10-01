<?php

namespace App\Domain\Employment\Contracts;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use Carbon\CarbonInterface;

/**
 * Phase 10 boundary: employee_positions stays the authoritative employment history and
 * AssignPositionAction its only writer. When an assignment names a position (seat), or vacates one,
 * the workforce module validates it here — inside the assignment's transaction, under the position's
 * row lock — and supplies the position's organisation dimensions.
 */
interface PositionAssignmentGuard
{
    /**
     * @param  array<string, mixed>  $attributes  the assignment input (position_id, fte, vacate_position, dimensions)
     * @return array{position_id: ?int, fte: ?float, dimensions: array<string, int|null>}
     */
    public function resolve(Employee $employee, ?EmployeePosition $current, array $attributes, CarbonInterface $from): array;
}
