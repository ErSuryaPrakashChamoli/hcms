<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity PO-4 (matrix 25 §25.6.5 #3, "Verify salary"): an optional reviewer tick per payroll entry — who checked the
 * calculated salary and when. Informational only: it never blocks validation, approval or finalization, and it is cleared
 * whenever the entry is recalculated (a recalculation replaces the run's entries, so the new entry starts unchecked).
 * Null = not checked, as every entry was before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('inputs')->comment('PO-4: who ticked the entry as checked (optional; never blocks)');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->foreign('reviewed_by', 'payroll_entries_reviewed_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropForeign('payroll_entries_reviewed_by_fk');
            $table->dropColumn(['reviewed_by', 'reviewed_at']);
        });
    }
};
