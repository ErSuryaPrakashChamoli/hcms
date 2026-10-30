<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity EX-D — Expense advances: money an employee asks for ahead of work expenses (expense advance policy),
 * approved, dispensed outside payroll (the payment reference is recorded; nothing is paid here), then settled against
 * approved expense reports oldest first, refunded or written off. The ledger is the source of truth for what is
 * outstanding (each entry keyed, so it is posted once); `outstanding` on the advance is its cached sum. Recovery through
 * payroll or the final settlement arrives with EX-E.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 500)->nullable();
            $table->date('needed_on')->nullable();
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2)->comment('in the request currency');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('amount_base', 14, 2);
            $table->decimal('approved_amount', 14, 2)->nullable()->comment('in the base currency');
            $table->json('attachments')->nullable();
            $table->string('status', 24)->default('requested')->comment('requested|approved|rejected|cancel_requested|cancelled|disbursed|settled|written_off');
            $table->string('disbursement_channel', 16)->default('bank')->comment('bank | payroll (from the policy; payroll payout is EX-E)');
            $table->date('disbursed_on')->nullable();
            $table->string('disbursement_reference', 120)->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('settle_by')->nullable();
            $table->decimal('outstanding', 14, 2)->default(0)->comment('cached sum of the ledger');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'employee_id', 'status'], 'exp_advances_emp_status_idx');
            $table->index(['tenant_id', 'status'], 'exp_advances_status_idx');
        });

        Schema::create('expense_advance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_advance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->comment('disbursed (+) | adjusted | refunded | written_off | recovered (-)');
            $table->decimal('amount', 14, 2)->comment('base currency; disbursed positive, the rest negative');
            $table->foreignId('expense_report_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_key', 64)->comment('idempotency key, unique per advance');
            $table->date('entry_date');
            $table->string('reference', 120)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'expense_advance_id', 'entry_key'], 'exp_adv_entries_key_unique');
            $table->index(['tenant_id', 'employee_id'], 'exp_adv_entries_emp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_advance_entries');
        Schema::dropIfExists('expense_advances');
    }
};
