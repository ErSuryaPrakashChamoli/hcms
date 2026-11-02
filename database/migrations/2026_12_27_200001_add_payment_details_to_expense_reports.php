<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity XO-4 (matrix 26 §26.4.4 #16, #18, #19; §26.4.3 #22) — Pay expenses depth:
 *
 * - payment_scheduled_for: the payroll month finance chose for a report paid with payroll (owner decision D-XO1 / EXP-7),
 *   kept as the first day of that month; null = the next payroll, as before. Only that month's payroll picks it up.
 * - payment_mode, paid_from_account_id, cheque_number, cheque_date: how a payment outside payroll was made (bank transfer,
 *   cheque or cash) and from which of the company's paying bank accounts (BP-13). Null = not recorded (as before).
 * - payment_note: the note finance keeps with the payment (HROne's payment remarks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_reports', function (Blueprint $table) {
            $table->date('payment_scheduled_for')->nullable()->after('payment_scheduled_by')->comment('payroll month chosen (its first day); null = the next payroll');
            $table->string('payment_mode', 16)->nullable()->after('payment_reference')->comment('outside payroll: bank_transfer, cheque or cash');
            $table->unsignedBigInteger('paid_from_account_id')->nullable()->after('payment_mode');
            $table->string('cheque_number', 30)->nullable()->after('paid_from_account_id');
            $table->date('cheque_date')->nullable()->after('cheque_number');
            $table->string('payment_note', 500)->nullable()->after('cheque_date');

            $table->foreign('paid_from_account_id', 'expense_reports_paid_from_account_fk')->references('id')->on('company_bank_accounts')->restrictOnDelete();
            $table->index(['tenant_id', 'payment_scheduled_for'], 'expense_reports_tenant_pay_month_idx');
        });
    }

    public function down(): void
    {
        Schema::table('expense_reports', function (Blueprint $table) {
            $table->dropForeign('expense_reports_paid_from_account_fk');
            $table->dropIndex('expense_reports_tenant_pay_month_idx');
            $table->dropColumn(['payment_scheduled_for', 'payment_mode', 'paid_from_account_id', 'cheque_number', 'cheque_date', 'payment_note']);
        });
    }
};
