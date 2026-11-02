<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity TX-7 (matrix 24 §24.5.1 #6–#7, owner decision D-TX5 — HROne "Delete punch"): a punch is never deleted. HR
 * (or, with the team option, the employee's manager) excludes it with a reason: processing_status becomes `excluded`, the
 * day is recalculated without it, and the punch stays as evidence (device data untouched). These columns say who excluded
 * it, when, why, and the processing status it had, so "Restore" puts it back exactly. Every existing row: all null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->timestamp('excluded_at')->nullable()->after('processed_at')->comment('TX-7: when the punch was excluded (null = counted)');
            $table->foreignId('excluded_by')->nullable()->after('excluded_at')->constrained('users', indexName: 'attendance_punches_excluded_by_fk')->nullOnDelete();
            $table->string('exclusion_reason', 500)->nullable()->after('excluded_by');
            $table->string('status_before_exclusion', 20)->nullable()->after('exclusion_reason')->comment('TX-7: the processing status restored by "Restore"');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropForeign('attendance_punches_excluded_by_fk');
            $table->dropColumn(['excluded_at', 'excluded_by', 'exclusion_reason', 'status_before_exclusion']);
        });
    }
};
