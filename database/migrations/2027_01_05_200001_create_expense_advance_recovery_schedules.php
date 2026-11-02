<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity XO-6 (matrix 26 §26.3.4 #1–#4, #6–#10; §26.3.1 #8; §26.7 #5) — Advance recovery desk:
 *
 * - expense_advance_recovery_schedules: a recovery HR scheduled for a dispensed advance (owner decision D-XO2 / EXP-8): the
 *   payroll month (`recover_in`, its first day) and the amount (at most what is outstanding and not yet scheduled). An advance
 *   with an open schedule is recovered only by the payroll of its scheduled months (never by the automatic recovery date);
 *   an advance without one is recovered exactly as before. A schedule is recovered once, by one finalized run (or the final
 *   settlement), then keeps the run, the amount and the date; cancelled schedules and those closed with their advance stay.
 * - expense_advance_entries.refund_mode, bank_name, cheque_number, cheque_date: how money returned outside payroll was paid
 *   (bank transfer, cheque or cash). Null = not recorded, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_advance_recovery_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('expense_advance_id');
            $table->unsignedBigInteger('employee_id');
            $table->date('recover_in')->comment('the payroll month (its first day)');
            $table->decimal('amount', 14, 2)->comment('base currency; at most what was outstanding and not yet scheduled');
            $table->string('status', 16)->default('scheduled')->comment('scheduled | recovered | cancelled | closed (the advance closed first)');
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('scheduled_by')->nullable();
            $table->decimal('recovered_amount', 14, 2)->nullable();
            $table->date('recovered_on')->nullable();
            // The finalized run (or paid final settlement) that recovered it — plain ids, as on the ledger entries (EX-E).
            $table->unsignedBigInteger('payroll_run_id')->nullable();
            $table->unsignedBigInteger('final_settlement_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('expense_advance_id', 'exp_adv_rec_sched_advance_fk')->references('id')->on('expense_advances')->cascadeOnDelete();
            $table->foreign('employee_id', 'exp_adv_rec_sched_employee_fk')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('scheduled_by', 'exp_adv_rec_sched_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by', 'exp_adv_rec_sched_cancel_by_fk')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'employee_id', 'status'], 'exp_adv_rec_sched_emp_status_idx');
            $table->index(['tenant_id', 'expense_advance_id', 'status'], 'exp_adv_rec_sched_adv_status_idx');
            $table->index(['tenant_id', 'recover_in'], 'exp_adv_rec_sched_month_idx');
        });

        Schema::table('expense_advance_entries', function (Blueprint $table) {
            $table->string('refund_mode', 16)->nullable()->after('note')->comment('refunds outside payroll: bank_transfer | cheque | cash');
            $table->string('bank_name', 120)->nullable()->after('refund_mode');
            $table->string('cheque_number', 30)->nullable()->after('bank_name');
            $table->date('cheque_date')->nullable()->after('cheque_number');
        });
    }

    public function down(): void
    {
        Schema::table('expense_advance_entries', function (Blueprint $table) {
            $table->dropColumn(['refund_mode', 'bank_name', 'cheque_number', 'cheque_date']);
        });
        Schema::dropIfExists('expense_advance_recovery_schedules');
    }
};
