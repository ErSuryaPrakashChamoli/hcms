<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity HP-2 (PY-02): reimbursements paid on claim. A reimbursement component marked pay_on_claim is not paid
 * each month; its monthly amount accrues to the employee's entitlement (ledger), and approved claims with bills are
 * paid through payroll against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->boolean('pay_on_claim')->default(false)->after('is_arrear_eligible')->comment('reimbursement paid against claims instead of every month');
        });

        Schema::create('reimbursement_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('financial_year')->comment('start year of the financial year');
            $table->date('claim_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('approved_amount', 14, 2)->nullable();
            $table->string('description', 500);
            $table->json('bills')->nullable()->comment('[{number, date, amount, vendor}]');
            $table->json('attachments')->nullable()->comment('private-disk paths of bill images');
            $table->string('status', 16)->default('pending')->comment('pending|approved|rejected|paid|cancelled');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->unsignedBigInteger('payroll_run_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status'], 'rc_tenant_employee_status_idx');
        });

        Schema::create('reimbursement_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('financial_year');
            $table->string('month', 7)->nullable();
            $table->string('kind', 16)->comment('opening|accrual|paid|lapse|adjustment');
            $table->decimal('amount', 14, 2)->comment('accrual/opening positive, paid/lapse negative');
            $table->string('entry_key', 64)->nullable()->comment('idempotency key');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'salary_component_id', 'entry_key'], 'rle_tenant_emp_comp_key_unique');
            $table->index(['tenant_id', 'employee_id', 'salary_component_id', 'financial_year'], 'rle_tenant_emp_comp_fy_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursement_ledger_entries');
        Schema::dropIfExists('reimbursement_claims');
        Schema::table('salary_components', function (Blueprint $table) {
            $table->dropColumn('pay_on_claim');
        });
    }
};
