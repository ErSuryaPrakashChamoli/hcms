<?php

namespace App\Domain\Skills\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** skill.* events (recorded, assessed). Carries the tenant-owned subject; never private notes. */
final class SkillEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly ?Employee $employee, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientEmployeeIds = []) {}
}
