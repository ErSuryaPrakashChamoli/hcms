<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity HP-2 (PY-01): employee loans and salary advances with an instalment schedule. Disbursement and
 * recovery go through payroll (payroll input source) or are recorded as paid by bank / outside payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->string('loan_type', 32);
            $table->decimal('principal', 14, 2);
            $table->decimal('interest_rate', 6, 3)->default(0)->comment('annual %, reducing balance');
            $table->unsignedSmallInteger('installments');
            $table->decimal('emi', 14, 2)->default(0);
            $table->string('first_due_month', 7)->comment('Y-m of the first recovery');
            $table->string('disbursed_through', 16)->default('payroll')->comment('payroll|bank');
            $table->string('status', 16)->default('pending')->comment('pending|approved|active|closed|rejected|cancelled');
            $table->string('reason', 500);
            $table->foreignId('guarantor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->date('disbursed_on')->nullable();
            $table->decimal('outstanding', 14, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number'], 'loans_tenant_number_unique');
            $table->index(['tenant_id', 'employee_id', 'status'], 'loans_tenant_employee_status_idx');
        });

        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('due_month', 7);
            $table->decimal('principal_part', 14, 2);
            $table->decimal('interest_part', 14, 2)->default(0);
            $table->decimal('amount', 14, 2);
            $table->string('status', 16)->default('due')->comment('due|recovered|prepaid|deferred|waived');
            $table->unsignedBigInteger('payroll_run_id')->nullable();
            $table->date('recovered_on')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'due_month', 'status'], 'li_tenant_employee_month_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loans');
    }
};
