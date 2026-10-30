<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity EX-F: a trip an expense report covers — kept in step with the trips of the report's expenses
 * (ExpenseReports). With expense.report.multiple_travel off a report covers one trip.
 */
#[Fillable(['tenant_id', 'expense_report_id', 'travel_request_id'])]
class ExpenseReportTravel extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'expense_report_travel';

    public function auditLabel(): string
    {
        return 'Expense report trip';
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class, 'expense_report_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }
}
