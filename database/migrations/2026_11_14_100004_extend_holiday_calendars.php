<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity (TO-6): restricted holidays prorated for joiners by joining month, cancellation of a restricted
 * holiday refused once attendance is processed (per role), and restricted holidays without a shift on the day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holiday_calendars', function (Blueprint $table) {
            $table->json('rh_prorate_rules')->nullable()->after('restricted_holiday_quota')->comment('[{from_month, to_month, quota}] for joiners');
            $table->json('rh_cancel_locked_for')->nullable()->after('rh_prorate_rules')->comment('roles that cannot cancel once processed: employee, manager, hr');
            $table->boolean('rh_without_shift')->default(true)->after('rh_cancel_locked_for');
        });
    }

    public function down(): void
    {
        Schema::table('holiday_calendars', fn (Blueprint $table) => $table->dropColumn(['rh_prorate_rules', 'rh_cancel_locked_for', 'rh_without_shift']));
    }
};
