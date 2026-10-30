<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Support\MasterCode;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity EX-A (Expense → Expense category): what an employee may claim, by amount (food, hotel, daily
 * allowance) or by mileage (2-wheeler, 4-wheeler: distance × rate). It carries every rule of HROne's category form —
 * limits (by employee dimension and location group, in an amount, a percentage of CTC or kilometres, per period), hourly
 * bands for daily allowances, receipts, frequency, tax, travel links and booking, attendance, joining date, the window
 * after the expense date and a declaration. Changes go through ExpenseCategories (validation, limit rows). Expense
 * policies choose categories and may override their limits (ExpenseLimits resolves both); receipts (EX-C) enforce them.
 * Inactive categories stay on old records but cannot be chosen. The code and the type are fixed once created.
 */
#[Fillable([
    'tenant_id', 'expense_group_id', 'name', 'code', 'description', 'type',
    'rate_per_km', 'odometer_required', 'location_input', 'from_location_required',
    'limit_basis', 'limit_dimension', 'ctc_component_id', 'limit_exception_allowed',
    'hourly_constraints_enabled', 'hourly_constraints', 'auto_fill_worked_hours',
    'description_required', 'receipt_required', 'receipt_required_above', 'reimbursable',
    'claim_frequency_period', 'claim_frequency_count', 'tax_applicable', 'gst_supply_type',
    'travel_link', 'travel_form', 'booking_service_id', 'booked_by',
    'before_joining_allowed', 'attendance_rule', 'submission_window_days', 'declaration_required', 'declaration_text',
    'sort_order', 'status',
])]
class ExpenseCategory extends Model
{
    use Auditable, BelongsToTenant, MasterCode;

    public const AMOUNT = 'amount';

    public const MILEAGE = 'mileage';

    protected $attributes = [
        'type' => self::AMOUNT, 'location_input' => 'none', 'limit_basis' => 'none', 'travel_link' => 'none', 'booked_by' => 'employee',
        'reimbursable' => true, 'odometer_required' => false, 'from_location_required' => false, 'limit_exception_allowed' => false,
        'hourly_constraints_enabled' => false, 'auto_fill_worked_hours' => false, 'description_required' => false, 'receipt_required' => false,
        'tax_applicable' => false, 'before_joining_allowed' => false, 'declaration_required' => false, 'sort_order' => 0, 'status' => 'active',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            if ($category->exists && $category->getRawOriginal('type') !== $category->getAttributes()['type']) {
                throw new ExpenseRuleViolation('The expense type (amount or mileage) cannot change once the category exists: add a new category instead.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'rate_per_km' => 'decimal:4', 'receipt_required_above' => 'decimal:2',
            'odometer_required' => 'boolean', 'from_location_required' => 'boolean', 'limit_exception_allowed' => 'boolean',
            'hourly_constraints_enabled' => 'boolean', 'auto_fill_worked_hours' => 'boolean', 'description_required' => 'boolean',
            'receipt_required' => 'boolean', 'reimbursable' => 'boolean', 'tax_applicable' => 'boolean', 'before_joining_allowed' => 'boolean',
            'declaration_required' => 'boolean',
            'hourly_constraints' => 'array', 'attendance_rule' => 'array', 'submission_window_days' => 'array',
            'claim_frequency_count' => 'integer', 'sort_order' => 'integer', 'status' => ActiveStatus::class,
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function isMileage(): bool
    {
        return $this->getAttributes()['type'] === self::MILEAGE;
    }

    public function isActive(): bool
    {
        return $this->status === ActiveStatus::Active;
    }

    /** "Name · Amount · Group", as HROne lists a category inside an expense policy. */
    public function displayLabel(): string
    {
        return implode(' · ', array_filter([$this->name, config("peopleos.expense.types.{$this->getAttributes()['type']}"), $this->relationLoaded('group') ? $this->group?->name : null]));
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ExpenseGroup::class, 'expense_group_id');
    }

    public function limits(): HasMany
    {
        return $this->hasMany(ExpenseCategoryLimit::class)->orderBy('id');
    }

    /** The CTC pay component a percentage limit is taken of (null = total CTC). */
    public function ctcComponent(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'ctc_component_id');
    }

    /** The helpdesk service a booking task is raised in when a trip with this category is approved (EX-F). */
    public function bookingService(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class, 'booking_service_id');
    }
}
