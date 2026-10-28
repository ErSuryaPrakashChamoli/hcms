<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave sessions are full, first_half or second_half (LeaveRequest::SESSIONS), but the columns were created eight
 * characters wide: MySQL in strict mode refused every half-day leave ("Data too long"); SQLite never checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('from_session', 16)->default('full')->change();
            $table->string('to_session', 16)->default('full')->change();
        });
    }

    public function down(): void
    {
        // Not narrowed back: half-day sessions would no longer fit.
    }
};
