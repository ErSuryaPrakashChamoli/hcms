<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity TX-6 (matrix 24 §24.5.1 #4, §24.8 #9 — "Change attendance status": From, To, "First half", "Second half"): an
 * HR day override may give each half of the day its own status (present, absent, weekly off, holiday, work from home, on
 * duty, field duty). Both null (every existing row, and every override that sets a whole-day status or worked minutes
 * only) keeps the override exactly as before; both set make the processor work the day out from the two halves (e.g. first
 * half present, second half absent = a half day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_overrides', function (Blueprint $table) {
            $table->string('first_half_status', 24)->nullable()->after('status')->comment('TX-6: status of the first half; null = the whole-day status / as calculated');
            $table->string('second_half_status', 24)->nullable()->after('first_half_status')->comment('TX-6: status of the second half; null = the whole-day status / as calculated');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_overrides', fn (Blueprint $table) => $table->dropColumn(['first_half_status', 'second_half_status']));
    }
};
