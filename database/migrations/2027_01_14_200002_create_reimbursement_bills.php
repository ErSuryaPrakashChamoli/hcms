<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-4 (decision): bills behind reimbursements paid every month with salary ("pay by default", pay_on_claim
 * off). From the organisation's bills date (payroll.reimbursement.bills_from), salary TDS treats what such a
 * reimbursement pays in the year beyond the bills on file as taxable salary. Bills are cancelled with a reason, never
 * edited or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimbursement_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('financial_year')->comment('start year of the financial year, as the claims ledger');
            $table->date('bill_date');
            $table->string('bill_number', 60)->nullable();
            $table->string('vendor', 120)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('active')->comment('active|cancelled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'financial_year', 'status'], 'rb_tenant_employee_fy_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursement_bills');
    }
};
