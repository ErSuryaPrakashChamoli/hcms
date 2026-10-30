<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity EX-E — expense money through payroll and the full and final settlement. An approved report may be
 * scheduled for payroll (expense.payment.step_required, or Pay expenses → Pay with payroll) and is then paid by the run
 * that carried it once that run is finalized; an advance approved for payroll payout is paid by the next run; a dispensed
 * advance not settled in time is recovered from the run due after its recovery date; at exit the final settlement pays
 * approved reports and recovers what is outstanding. Run and settlement ids are plain references, as on the HP-2 tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_reports', function (Blueprint $table) {
            $table->timestamp('payment_scheduled_at')->nullable()->after('payment_channel')->comment('when the report was scheduled for payroll');
            $table->unsignedBigInteger('payment_scheduled_by')->nullable()->after('payment_scheduled_at')->comment('null = scheduled on approval (no payment step)');
            $table->unsignedBigInteger('payroll_run_id')->nullable()->after('payment_reference')->comment('the finalized run that paid it');
            $table->unsignedBigInteger('final_settlement_id')->nullable()->after('payroll_run_id')->comment('the full and final settlement that paid it');
        });

        Schema::table('expense_advances', function (Blueprint $table) {
            $table->unsignedBigInteger('disbursed_payroll_run_id')->nullable()->after('disbursed_by')->comment('the finalized run that paid it out');
            $table->date('recovery_due_on')->nullable()->after('settle_by')->comment('payroll recovers what is outstanding from this date (null: not through payroll)');
        });

        Schema::table('expense_advance_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('payroll_run_id')->nullable()->after('expense_report_id');
            $table->unsignedBigInteger('final_settlement_id')->nullable()->after('payroll_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('expense_advance_entries', function (Blueprint $table) {
            $table->dropColumn(['payroll_run_id', 'final_settlement_id']);
        });
        Schema::table('expense_advances', function (Blueprint $table) {
            $table->dropColumn(['disbursed_payroll_run_id', 'recovery_due_on']);
        });
        Schema::table('expense_reports', function (Blueprint $table) {
            $table->dropColumn(['payment_scheduled_at', 'payment_scheduled_by', 'payroll_run_id', 'final_settlement_id']);
        });
    }
};
