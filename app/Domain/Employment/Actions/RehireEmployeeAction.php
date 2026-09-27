<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Re-employment (contract §3, ADR-0002): the SAME Employee returns from alumni to active with a
 * new effective-dated position and, optionally, a new line manager. Person, employee code,
 * history, documents and lifecycle transitions are preserved; never a second employee master.
 */
final class RehireEmployeeAction
{
    public function __construct(
        private readonly LifecycleEngine $lifecycle,
        private readonly AssignPositionAction $positions,
        private readonly ChangeManagerAction $managers,
    ) {}

    /** @param  array<string, mixed>  $position */
    public function handle(Employee $employee, array $position, CarbonInterface|string|null $rejoinDate = null, ?int $managerId = null, ?string $reason = null): Employee
    {
        if ($employee->lifecycle_state !== LifecycleState::Alumni) {
            throw new InvalidArgumentException('Only an alumni can be re-employed; current state is '.$employee->lifecycle_state->getLabel().'.');
        }

        $from = Carbon::parse($rejoinDate ?? now())->startOfDay();

        return DB::transaction(function () use ($employee, $position, $from, $managerId, $reason) {
            $this->lifecycle->transition($employee, LifecycleState::Active, $from, $reason ?? 'Re-employed', ['rehire' => true]);
            $employee->withAuditReason($reason)->update(['joining_date' => $from, 'exit_date' => null, 'confirmation_date' => null]);

            $this->positions->handle($employee, $position, 'rehire', $from, $reason);

            if ($managerId !== null) {
                $this->managers->handle($employee, Employee::query()->findOrFail($managerId), 'line', $from, $reason);
            }

            EmploymentEvent::dispatch('employee.rehired', $employee, $employee, ['rejoin_date' => $from->toDateString(), 'reason' => $reason]);

            return $employee->refresh();
        });
    }
}
