<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity EX-C — Expense receipts and reports. A receipt is one expense an employee files against a category
 * of their expense policy (every category rule is checked when it is captured); a report groups receipts for approval and
 * is split into parts, one per workflow group (category, expense group, or the exceptional expenses), each decided on its
 * own, line by line. Nothing here pays anything: an approved report's payable amount waits for payroll (EX-E) or for an
 * outside payment recorded on Pay expenses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('status', 24)->default('draft')->comment('draft|submitted|returned|approved|partly_approved|rejected|paid|cancelled');
            $table->char('currency', 3)->comment('the base currency every amount is in');
            $table->decimal('total_claimed', 14, 2)->default(0);
            $table->decimal('total_approved', 14, 2)->default(0);
            $table->decimal('reimbursable_approved', 14, 2)->default(0)->comment('approved lines the employee is repaid for (company-paid lines are not)');
            $table->decimal('advance_adjusted', 14, 2)->default(0)->comment('open advances settled against this report (EX-D, FIFO)');
            $table->decimal('net_payable', 14, 2)->default(0)->comment('reimbursable approved less advances adjusted');
            $table->unsignedTinyInteger('window_cycle')->nullable()->comment('submission window cycle (1 or 2) when windows are on');
            $table->boolean('filed_on_behalf')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->string('return_note', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            // Payment (recorded only: Pay expenses records an outside payment; payroll payment arrives with EX-E).
            $table->string('payment_channel', 16)->nullable()->comment('bank (outside payroll) | payroll | settlement');
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'employee_id', 'status'], 'exp_reports_emp_status_idx');
            $table->index(['tenant_id', 'status'], 'exp_reports_status_idx');
        });

        Schema::create('expense_report_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('workflow_group', 64)->comment('all | category:<code> | group:<code> | exceptional');
            $table->string('kind', 16)->default('standard')->comment('standard | exceptional');
            $table->string('label', 160);
            $table->string('status', 24)->default('pending')->comment('pending|approved|partly_approved|rejected|returned|cancelled');
            $table->decimal('total_claimed', 14, 2)->default(0);
            $table->decimal('total_approved', 14, 2)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'exp_report_parts_status_idx');
        });

        Schema::create('expense_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('policy_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_report_part_id')->nullable()->constrained()->nullOnDelete();
            $table->date('expense_date');
            $table->string('type', 16)->comment('amount | mileage (the category type when filed)');
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2)->comment('in the receipt currency');
            $table->decimal('exchange_rate', 18, 8)->default(1)->comment('receipt currency to base, on the expense date');
            $table->decimal('base_amount', 14, 2);
            // Mileage.
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->decimal('computed_distance_km', 10, 2)->nullable()->comment('odometer or map distance before any edit');
            $table->string('distance_source', 16)->nullable()->comment('manual | odometer | map');
            $table->string('from_place', 160)->nullable();
            $table->string('to_place', 160)->nullable();
            $table->decimal('odometer_start', 12, 1)->nullable();
            $table->decimal('odometer_end', 12, 1)->nullable();
            $table->unsignedSmallInteger('worked_minutes')->nullable()->comment('hours behind an hourly-band daily allowance');
            // Form fields.
            $table->string('location_city', 120)->nullable();
            $table->string('location_state', 64)->nullable();
            $table->foreignId('expense_location_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 500)->nullable();
            $table->string('merchant', 160)->nullable();
            $table->string('invoice_number', 64)->nullable()->comment('upper case, trimmed (duplicate-invoice rule)');
            $table->json('participants')->nullable();
            $table->string('supplier_gstin', 15)->nullable();
            $table->string('gst_supply_type', 16)->nullable();
            $table->text('description')->nullable();
            $table->json('attachments')->nullable()->comment('private documents-disk paths');
            $table->json('attachment_hashes')->nullable()->comment('sha256 of each attachment (duplicate check)');
            // Checks.
            $table->unsignedTinyInteger('duplicate_score')->nullable();
            $table->unsignedBigInteger('duplicate_of_id')->nullable();
            $table->json('flags')->nullable()->comment('suspected_duplicate, same_invoice_number, over_limit, distance_edited');
            $table->boolean('is_exceptional')->default(false);
            $table->string('exception_reason', 500)->nullable();
            $table->decimal('limit_amount', 14, 2)->nullable()->comment('the most this claim could take within its limits, when filed');
            $table->decimal('over_limit_amount', 14, 2)->default(0);
            $table->decimal('claimable_amount', 14, 2);
            $table->boolean('reimbursable')->default(true);
            $table->timestamp('declaration_accepted_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('workflow_group', 64)->nullable();
            // Decision.
            $table->decimal('approved_amount', 14, 2)->nullable();
            $table->string('approver_note', 500)->nullable();
            $table->string('status', 24)->default('unreported')->comment('unreported|reported|approved|partly_approved|rejected|expired|withdrawn');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'employee_id', 'expense_date'], 'exp_receipts_emp_date_idx');
            $table->index(['tenant_id', 'status', 'expense_date'], 'exp_receipts_status_date_idx');
            $table->index(['tenant_id', 'invoice_number'], 'exp_receipts_invoice_idx');
        });

        Schema::create('expense_receipt_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_tax_type_id')->constrained()->restrictOnDelete();
            $table->decimal('rate', 6, 2)->nullable()->comment('as printed on the receipt; never a statutory value');
            $table->decimal('amount', 14, 2)->comment('in the receipt currency');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_receipt_taxes');
        Schema::dropIfExists('expense_receipts');
        Schema::dropIfExists('expense_report_parts');
        Schema::dropIfExists('expense_reports');
    }
};
