<?php

namespace App\Domain\Performance\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** performance.* events (cycle launched, review assigned/submitted, appraisal finalized, feedback, goals, PIP). */
final class PerformanceEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly ?Employee $employee, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientEmployeeIds = []) {}
}
