<?php

namespace App\Domain\Compensation\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 11 compensation events, separate from audit: compensation.change.submitted / reviewed /
 * approved / rejected / returned / cancelled / scheduled / effective / corrected,
 * compensation.structure.approved, compensation.range.approved, compensation.cycle.* and reminders.
 * The context never carries amounts, reasons or notes; recipients are explicit users.
 */
final class CompensationEvent
{
    use Dispatchable;

    /**
     * @param  array<string, scalar|null>  $context
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(public readonly string $name, public readonly ?Employee $employee, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientUserIds = []) {}
}
