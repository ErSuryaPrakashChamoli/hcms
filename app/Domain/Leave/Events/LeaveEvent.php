<?php

namespace App\Domain\Leave\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** leave.requested / approved / rejected / cancelled / encashment_* / balance_adjusted / accrued */
final class LeaveEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly Employee $employee, public readonly Model $subject, public readonly array $context = []) {}
}
