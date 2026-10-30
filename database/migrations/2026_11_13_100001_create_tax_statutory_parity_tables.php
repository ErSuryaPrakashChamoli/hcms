<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity HP-3 (tax and statutory):
 * - tax_income_entries: perquisites (PY-17) and income paid outside this payroll — out-of-salary payments, months
 *   run on another system before go-live (PY-07, PY-18, PY-25) — that TDS projects with the salary;
 * - statutory_overrides: an audited PF / ESI / PT / LWF / TDS amount for one employee in one pay period (PY-14);
 * - employee_pf_options: voluntary PF and the employee's PF wage basis, effective-dated (PY-15);
 * - proof submission on tds_employee_investments (PY-12) and previous-employer details on the declaration (PY-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_income_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('financial_year', 9);
            $table->string('kind', 16)->comment('perquisite|outside_payroll');
            $table->string('perquisite_type', 40)->nullable();
            $table->string('description', 255);
            $table->date('paid_on')->nullable();
            $table->decimal('amount', 14, 2)->comment('perquisite: value for the year; outside payroll: amount paid');
            $table->decimal('recovered', 14, 2)->default(0)->comment('perquisite: amount the employee pays back');
            $table->decimal('taxable_amount', 14, 2);
            $table->decimal('tds_deducted', 14, 2)->default(0);
            $table->decimal('pf_employee', 14, 2)->default(0);
            $table->decimal('professional_tax', 14, 2)->default(0);
            $table->string('reference', 120)->nullable();
            $table->string('status', 16)->default('active')->comment('active|cancelled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'financial_year', 'status'], 'tie_tenant_employee_fy_idx');
        });

        Schema::create('statutory_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->comment('PF_EE|PF_ER|VPF|ESI_EE|ESI_ER|PT|LWF_EE|LWF_ER|TDS');
            $table->decimal('amount', 14, 2);
            $table->string('reason', 500);
            $table->string('status', 16)->default('active')->comment('active|cancelled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'payroll_period_id', 'employee_id', 'status'], 'so_tenant_period_employee_idx');
        });

        Schema::create('employee_pf_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->string('vpf_mode', 16)->nullable()->comment('null|percent|amount');
            $table->decimal('vpf_value', 14, 2)->nullable();
            $table->string('wage_basis', 16)->nullable()->comment('null = establishment rule | ceiling | actual');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from'], 'epo_employee_from_unique');
            $table->index(['tenant_id', 'employee_id'], 'epo_tenant_employee_idx');
        });

        Schema::table('tds_employee_investments', function (Blueprint $table) {
            $table->json('proof_files')->nullable()->after('employee_document_id');
            $table->decimal('proof_claimed_amount', 14, 2)->nullable()->after('proof_files');
            $table->timestamp('proof_submitted_at')->nullable()->after('proof_claimed_amount');
        });

        Schema::table('employee_tax_declarations', function (Blueprint $table) {
            $table->text('previous_employer')->nullable()->after('previous_employer_tds')->comment('encrypted: name, TAN, period, PF and PT there');
        });
    }

    public function down(): void
    {
        Schema::table('employee_tax_declarations', fn (Blueprint $table) => $table->dropColumn('previous_employer'));
        Schema::table('tds_employee_investments', fn (Blueprint $table) => $table->dropColumn(['proof_files', 'proof_claimed_amount', 'proof_submitted_at']));
        Schema::dropIfExists('employee_pf_options');
        Schema::dropIfExists('statutory_overrides');
        Schema::dropIfExists('tax_income_entries');
    }
};
