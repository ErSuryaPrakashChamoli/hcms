<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity XO-2 (matrix 26 §26.4.3 #14 "Duplicity : View", "Similar Receipts"): the receipts the duplicate check
 * matched when the receipt was captured or last changed — a list of {id, score, reason} (reason: similar = scored at or
 * above the threshold, invoice = the same invoice number from the same merchant). The verdicts (`flags`,
 * `duplicate_score`, `duplicate_of_id`) are unchanged; this only keeps whom they were reached against, so the receipt
 * page can name them. Null = nothing matched (or captured before XO-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_receipts', function (Blueprint $table) {
            $table->json('duplicate_matches')->nullable()->after('duplicate_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('expense_receipts', function (Blueprint $table) {
            $table->dropColumn('duplicate_matches');
        });
    }
};
