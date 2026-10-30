<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity XO-1 (matrix 26 §26.2.4 #1, §26.3.3 #1: Travel cancellations, Advance cancellations): when an approver
 * refuses a cancellation ("Keep trip" / "Keep advance") the trip or advance records when, so the cancellation registers
 * still list it after it went back to approved. Null = no cancellation was ever refused. Written only by
 * TravelRequests::rejectCancellation and ExpenseAdvances::rejectCancellation; never cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->timestamp('cancel_refused_at')->nullable()->after('cancel_requested_at');
        });
        Schema::table('expense_advances', function (Blueprint $table) {
            $table->timestamp('cancel_refused_at')->nullable()->after('cancel_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->dropColumn('cancel_refused_at');
        });
        Schema::table('expense_advances', function (Blueprint $table) {
            $table->dropColumn('cancel_refused_at');
        });
    }
};
