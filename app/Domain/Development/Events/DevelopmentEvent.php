<?php

namespace App\Domain\Development\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** development.* events (plan created, activated, completed, milestone due). Never private notes. */
final class DevelopmentEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly ?Employee $employee, public readonly Model $subject, public readonly array $context = [], public readonly array $recipientEmployeeIds = []) {}
}
