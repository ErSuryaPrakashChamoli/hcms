<?php

namespace App\Domain\Talent\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 9 talent.* events. They carry the tenant-owned subject and never confidential text; recipients
 * are named explicitly (employee ids and / or user ids) — nobody is notified by default.
 */
final class TalentEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $recipientEmployeeIds
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(public readonly string $name, public readonly ?Employee $employee, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientEmployeeIds = [], public readonly array $recipientUserIds = []) {}
}
