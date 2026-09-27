<?php

namespace App\Domain\Lifecycle\Events;

use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event (blueprint §88): employee.joined / confirmed / exited / alumni_created all flow
 * through this one event; listeners branch on $to.
 */
final class EmployeeLifecycleChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Employee $employee,
        public readonly ?LifecycleState $from,
        public readonly LifecycleState $to,
        public readonly CarbonInterface $effectiveDate,
        public readonly ?string $reason,
    ) {}

    public function name(): string
    {
        return 'employee.'.$this->to->value;
    }
}
