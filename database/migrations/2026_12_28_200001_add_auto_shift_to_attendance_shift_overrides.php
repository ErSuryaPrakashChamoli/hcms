<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity TX-5 (matrix 24 §24.2.1 #7, §24.8 #8 — "Do you want to allow auto shift correction?"): a rostered day may
 * let the day's first check-in correct its shift, the way a work schedule's automatic shift correction (TO-5) does on
 * days that are not rostered. Null (every existing row, and rows written by the earlier roster paths) and false keep the
 * rostered shift exactly as before; true lets ShiftResolver / the processor pick the shift nearest the first check-in
 * among the rostered shift and the shifts the employee's work schedule lists for correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_shift_overrides', function (Blueprint $table) {
            $table->boolean('auto_shift')->nullable()->after('shift_id')->comment('TX-5: true = automatic shift correction on this rostered day; null / false = the rostered shift as is');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_shift_overrides', fn (Blueprint $table) => $table->dropColumn('auto_shift'));
    }
};
