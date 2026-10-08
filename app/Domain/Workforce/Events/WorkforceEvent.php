<?php

namespace App\Domain\Workforce\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 10 workforce events: workforce.position.created / approved / opened / frozen / unfrozen /
 * on_hold / abolished / closed / occupied / vacated, workforce.position.change_requested / changed,
 * workforce.plan.submitted / approved / rejected / published / superseded, workforce.scenario.approved,
 * workforce.budget.approved and reminders. The subject is tenant-owned; recipients are explicit users.
 */
final class WorkforceEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(public readonly string $name, public readonly ?Employee $employee, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientUserIds = []) {}
}
