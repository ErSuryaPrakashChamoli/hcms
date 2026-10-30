<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity (TO-3): documents attached to regularisation and time-office requests, the on-duty type, and
 * the day a week-off change takes off instead of the weekly off worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('reason');
        });

        Schema::table('time_office_requests', function (Blueprint $table) {
            $table->date('swap_date')->nullable()->after('to_date');
            $table->string('category', 60)->nullable()->after('session')->comment('on-duty type');
            $table->json('attachments')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('time_office_requests', fn (Blueprint $table) => $table->dropColumn(['swap_date', 'category', 'attachments']));
        Schema::table('attendance_regularisations', fn (Blueprint $table) => $table->dropColumn('attachments'));
    }
};
