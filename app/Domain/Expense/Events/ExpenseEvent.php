<?php

namespace App\Domain\Expense\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HROne setup parity EX-C/EX-D: expense.report_submitted, expense.exceptional_submitted (a report part, carrying its
 * workflow group), expense.report_approved / _partly_approved / _rejected / _returned / _cancelled / _paid,
 * expense.advance_requested, expense.advance_cancel_requested, expense.advance_approved / _rejected / _cancelled /
 * _dispensed / _settled / _written_off; EX-F expense.travel_requested (with its workflow group), expense.travel_approved /
 * _rejected / _cancel_requested / _cancelled / _booking_requested / _booked. Workflows start on the *_submitted / *_requested ones; notification rules may
 * listen to all. Context holds references and amounts only. Recipients get an in-app note when no rule sends one.
 */
final class ExpenseEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(
        public readonly string $name,
        public readonly Employee $employee,
        public readonly Model $subject,
        public readonly array $context = [],
        public readonly array $recipientUserIds = [],
    ) {}
}
