<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity EX-C: one expense an employee files (HROne "Expense receipt"): an amount, or a distance for a
 * mileage category, on a date, against a category of their expense policy. ExpenseReceipts checks every category rule
 * when it is captured and records what the rules made of it — the limit in force, what is claimable, any amount over the
 * limit, an exceptional expense with its reason, duplicate suspicions. Amounts are kept in the receipt currency and in
 * the base currency (at the rate of the expense date). Receipts are reported in an expense report and decided line by
 * line; unreported ones may expire (expense.expiry.*). Custom fields of the `expense_receipt` entity hold "Field one …".
 * EX-F: a receipt of a travel-linked category points at the employee's approved trip (`travel_request_id`).
 */
#[Fillable([
    'tenant_id', 'number', 'employee_id', 'expense_category_id', 'policy_version_id', 'expense_report_id', 'expense_report_part_id', 'travel_request_id',
    'expense_date', 'type', 'currency', 'amount', 'exchange_rate', 'base_amount',
    'distance_km', 'computed_distance_km', 'distance_source', 'from_place', 'to_place', 'odometer_start', 'odometer_end', 'worked_minutes',
    'location_city', 'location_state', 'expense_location_group_id', 'purpose', 'merchant', 'invoice_number', 'participants',
    'supplier_gstin', 'gst_supply_type', 'description', 'attachments', 'attachment_hashes',
    'duplicate_score', 'duplicate_of_id', 'duplicate_matches', 'flags', 'is_exceptional', 'exception_reason', 'limit_amount', 'over_limit_amount', 'claimable_amount',
    'reimbursable', 'declaration_accepted_at', 'expires_on', 'workflow_group', 'approved_amount', 'approver_note', 'status',
    'withdrawn_at', 'expired_at', 'created_by',
])]
class ExpenseReceipt extends Model
{
    use Auditable, BelongsToTenant, HasCustomFields, ScopedByEmployee;

    public const STATUSES = [
        'unreported' => 'Unreported', 'reported' => 'In a report', 'approved' => 'Approved', 'partly_approved' => 'Partly approved',
        'rejected' => 'Rejected', 'expired' => 'Expired', 'withdrawn' => 'Withdrawn',
    ];

    /** Statuses whose amount counts against limits and frequencies (claimed and not refused). */
    public const COUNTING = ['unreported', 'reported', 'approved', 'partly_approved'];

    protected $attributes = ['status' => 'unreported', 'is_exceptional' => false, 'reimbursable' => true, 'over_limit_amount' => 0, 'exchange_rate' => 1];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date', 'expires_on' => 'date', 'declaration_accepted_at' => 'datetime', 'withdrawn_at' => 'datetime', 'expired_at' => 'datetime',
            'amount' => 'decimal:2', 'exchange_rate' => 'decimal:8', 'base_amount' => 'decimal:2', 'distance_km' => 'decimal:2', 'computed_distance_km' => 'decimal:2',
            'odometer_start' => 'decimal:1', 'odometer_end' => 'decimal:1', 'limit_amount' => 'decimal:2', 'over_limit_amount' => 'decimal:2',
            'claimable_amount' => 'decimal:2', 'approved_amount' => 'decimal:2',
            'worked_minutes' => 'integer', 'duplicate_score' => 'integer', 'is_exceptional' => 'boolean', 'reimbursable' => 'boolean',
            'participants' => 'array', 'attachments' => 'array', 'attachment_hashes' => 'array', 'flags' => 'array', 'duplicate_matches' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return 'Expense receipt '.($this->getAttributes()['number'] ?? '');
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'attachments', 'attachment_hashes'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class, 'expense_report_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(ExpenseReportPart::class, 'expense_report_part_id');
    }

    /** EX-F: the approved trip the expense belongs to (travel-linked categories). */
    public function travelRequest(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class);
    }

    public function locationGroup(): BelongsTo
    {
        return $this->belongsTo(ExpenseLocationGroup::class, 'expense_location_group_id');
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(ExpenseReceiptTax::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, (array) $this->flags, true);
    }

    /** What the receipt counts for: the approved amount once decided, else what is claimable. */
    public function countedAmount(): float
    {
        return (float) ($this->approved_amount ?? $this->claimable_amount);
    }
}
