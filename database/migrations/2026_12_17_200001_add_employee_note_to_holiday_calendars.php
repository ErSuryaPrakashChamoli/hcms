<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity MS-12 (My space → Holiday calendar, "Note :"): the calendar's note for employees, shown under their
 * holiday list on My holidays. Optional; null on existing calendars, which keeps every page as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holiday_calendars', function (Blueprint $table) {
            $table->text('employee_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('holiday_calendars', function (Blueprint $table) {
            $table->dropColumn('employee_note');
        });
    }
};
