<?php

namespace App\Domain\Payroll\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** payroll.calculated / approved / finalized / paid / payslip_generated / exception */
final class PayrollEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly Model $subject, public readonly array $context = []) {}
}
