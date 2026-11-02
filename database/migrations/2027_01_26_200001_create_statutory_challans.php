<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity PO-13 (matrix 25 §25.6.12, "Generate challan"): the statutory challan register — the payment made for a
 * month's PF, ESI, PT or LWF contribution (per establishment) or for the TDS deducted from salaries (per legal entity, the
 * TAN), with its challan no. / TRRN / CRN, BSR code, date, mode, bank, amount, interest, penalty and receipt. A row is
 * recorded by someone with compliance.returns.file (never the approver of the return it pays), may be verified by a second
 * person, and is cancelled with a reason (never deleted). A TDS challan carries its allocation per employee, read from
 * finalized payroll (the TDS ledger): what each employee's deduction contributed to the deposit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_challans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies', 'id', 'statutory_challans_company_fk')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities', 'id', 'statutory_challans_legal_entity_fk')->restrictOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained('establishments', 'id', 'statutory_challans_establishment_fk')->restrictOnDelete();
            $table->foreignId('statutory_registration_id')->nullable()->constrained('statutory_registrations', 'id', 'statutory_challans_registration_fk')->restrictOnDelete();
            $table->foreignId('statutory_return_id')->nullable()->constrained('statutory_returns', 'id', 'statutory_challans_return_fk')->restrictOnDelete();
            $table->string('statute', 8);
            $table->string('state_code', 8)->nullable();
            $table->string('financial_year', 9);
            $table->date('period_month');
            $table->string('alias', 100)->nullable();
            $table->string('challan_number', 64);
            $table->string('acknowledgement_number', 64)->nullable();
            $table->string('bsr_code', 16)->nullable();
            $table->string('nature_of_payment', 16)->nullable();
            $table->date('paid_on');
            $table->string('payment_mode', 24);
            $table->string('bank_name', 120)->nullable();
            $table->string('branch', 120)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('interest', 14, 2)->default(0);
            $table->decimal('penalty', 14, 2)->default(0);
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('recorded');
            // statute|legal entity|challan number while not cancelled: the same challan cannot be recorded twice.
            $table->string('active_key', 160)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users', 'id', 'statutory_challans_recorded_by_fk')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users', 'id', 'statutory_challans_verified_by_fk')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users', 'id', 'statutory_challans_cancelled_by_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'active_key'], 'statutory_challans_tenant_active_key_unique');
            $table->index(['tenant_id', 'statute', 'financial_year'], 'statutory_challans_tenant_statute_fy_idx');
            $table->index(['tenant_id', 'establishment_id', 'period_month'], 'statutory_challans_tenant_establishment_month_idx');
            $table->index(['tenant_id', 'legal_entity_id', 'statute', 'period_month'], 'statutory_challans_tenant_entity_month_idx');
        });

        Schema::create('statutory_challan_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statutory_challan_id')->constrained('statutory_challans', 'id', 'statutory_challan_allocations_challan_fk')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities', 'id', 'statutory_challan_allocations_entity_fk')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees', 'id', 'statutory_challan_allocations_employee_fk')->restrictOnDelete();
            $table->foreignId('tds_annual_ledger_id')->nullable()->constrained('tds_annual_ledgers', 'id', 'statutory_challan_allocations_ledger_fk')->restrictOnDelete();
            $table->date('period_month');
            $table->decimal('tds_deducted', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->timestamp('created_at')->nullable();

            $table->unique(['statutory_challan_id', 'employee_id'], 'statutory_challan_allocations_challan_employee_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'period_month', 'employee_id'], 'statutory_challan_allocations_entity_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_challan_allocations');
        Schema::dropIfExists('statutory_challans');
    }
};
