<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity XO-5 (matrix 26 §26.3.2 #1, #2, #8, #9; §26.7 #2) — Advance desk and on-behalf filing:
 *
 * - filed_on_behalf: the advance was requested by HR (or a manager) for the employee (`requested_by` names who).
 * - dispensed_amount: with partial dispense on (owner decision D-XO8 / EXP-14), what has been paid out so far when it is
 *   less than the approved amount; null = the whole approved amount (as before — every advance dispensed in one go).
 * - disbursement_note: the note finance kept with the payout (HROne's dispense remarks; also on the ledger entry).
 * - remainder_lapsed_at: when the part of a partly dispensed advance never paid out lapsed (the advance was settled,
 *   recovered or written off, its rest cancelled, or its trip cancelled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_advances', function (Blueprint $table) {
            $table->boolean('filed_on_behalf')->default(false)->after('requested_by');
            $table->decimal('dispensed_amount', 14, 2)->nullable()->after('disbursed_on')->comment('paid out so far when less than approved; null = the approved amount');
            $table->string('disbursement_note', 500)->nullable()->after('disbursement_reference');
            $table->timestamp('remainder_lapsed_at')->nullable()->after('dispensed_amount');

            $table->index(['tenant_id', 'disbursed_on'], 'exp_advances_disbursed_on_idx');
        });
    }

    public function down(): void
    {
        Schema::table('expense_advances', function (Blueprint $table) {
            $table->dropIndex('exp_advances_disbursed_on_idx');
            $table->dropColumn(['filed_on_behalf', 'dispensed_amount', 'disbursement_note', 'remainder_lapsed_at']);
        });
    }
};
