<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity HP-1 (Time Office): employee time-office requests (short leave, on duty, shift change,
 * overtime and comp-off claims, restricted holidays), day-level shift overrides (roster), HR day
 * overrides, late-mark penalties, attendance arrears, geofences with location requests, registered
 * check-in devices and leave donations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_office_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24)->comment('short_leave|on_duty|shift_change|overtime|comp_off|restricted_holiday');
            $table->string('status', 24)->default('pending')->comment('pending|approved|rejected|cancelled|cancel_requested');
            $table->date('from_date');
            $table->date('to_date');
            $table->string('session', 16)->default('full')->comment('full|first_half|second_half|hours');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('minutes')->nullable();
            $table->decimal('days', 6, 2)->nullable();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('holiday_id')->nullable()->constrained()->nullOnDelete();
            $table->date('expires_on')->nullable()->comment('comp-off credit expiry');
            $table->string('reason', 500);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('effects')->nullable()->comment('what approval did: override ids, ledger entry ids, record snapshots');
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'from_date'], 'tor_tenant_employee_from_idx');
            $table->index(['tenant_id', 'type', 'status'], 'tor_tenant_type_status_idx');
        });

        Schema::create('attendance_shift_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete()->comment('null = weekly off that day');
            $table->string('source', 16)->default('roster')->comment('roster|request|import');
            $table->foreignId('time_office_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'date'], 'aso_tenant_employee_date_unique');
        });

        Schema::create('attendance_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 24)->nullable()->comment('override the day status (present, absent, half_day, …)');
            $table->unsignedInteger('worked_minutes')->nullable()->comment('override the worked minutes');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'date'], 'ao_tenant_employee_date_unique');
        });

        Schema::create('attendance_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date')->comment('the late day that crossed the threshold');
            $table->string('month', 7)->comment('Y-m the late marks were counted in');
            $table->string('rule', 24)->default('late_marks');
            $table->string('action', 16)->comment('warn|half_day|lop');
            $table->decimal('days', 4, 2)->default(0);
            $table->unsignedSmallInteger('late_count');
            $table->json('record_ids')->nullable();
            $table->string('status', 16)->default('applied')->comment('applied|waived');
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('waive_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'date', 'rule'], 'ap_tenant_employee_date_rule_unique');
            $table->index(['tenant_id', 'month'], 'ap_tenant_month_idx');
        });

        Schema::create('attendance_arrears', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status_before', 24);
            $table->string('status_after', 24);
            $table->decimal('paid_before', 4, 2);
            $table->decimal('paid_after', 4, 2);
            $table->decimal('delta_days', 4, 2)->comment('paid_after - paid_before');
            $table->string('status', 16)->default('pending')->comment('pending|settled|void');
            $table->unsignedBigInteger('settled_period_id')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status'], 'aa_tenant_employee_status_idx');
        });

        Schema::create('attendance_geo_fences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->default(200);
            $table->string('address', 500)->nullable();
            $table->string('source', 16)->default('hr')->comment('hr|request|import');
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'agf_tenant_status_idx');
        });

        Schema::create('attendance_location_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->default(200);
            $table->string('address', 500)->nullable();
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('attendance_geo_fence_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_employee_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_identifier', 128);
            $table->string('platform', 32)->nullable();
            $table->string('label')->nullable();
            $table->string('status', 16)->default('pending')->comment('approved|pending|blocked');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'device_identifier'], 'aed_tenant_employee_device_unique');
            $table->index(['tenant_id', 'device_identifier'], 'aed_tenant_device_idx');
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->string('geo_status', 16)->nullable()->after('longitude')->comment('inside|outside|no_location');
            $table->foreignId('attendance_geo_fence_id')->nullable()->after('geo_status')->constrained()->nullOnDelete();
            $table->unsignedInteger('geo_distance_m')->nullable()->after('attendance_geo_fence_id');
            $table->string('device_identifier', 128)->nullable()->after('geo_distance_m');
        });

        Schema::table('holiday_calendars', function (Blueprint $table) {
            $table->boolean('optional_requires_selection')->default(false)->after('status')->comment('optional holidays apply only to employees who chose them');
            $table->unsignedTinyInteger('restricted_holiday_quota')->nullable()->after('optional_requires_selection');
        });

        Schema::table('leave_encashments', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('review_note');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');
        });

        Schema::create('leave_donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete()->comment('the donor');
            $table->foreignId('recipient_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->decimal('days', 6, 2);
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending')->comment('pending|approved|rejected|cancelled');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'ld_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_donations');
        Schema::table('leave_encashments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
        Schema::table('holiday_calendars', function (Blueprint $table) {
            $table->dropColumn(['optional_requires_selection', 'restricted_holiday_quota']);
        });
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_geo_fence_id');
            $table->dropColumn(['geo_status', 'geo_distance_m', 'device_identifier']);
        });
        Schema::dropIfExists('attendance_employee_devices');
        Schema::dropIfExists('attendance_location_requests');
        Schema::dropIfExists('attendance_geo_fences');
        Schema::dropIfExists('attendance_arrears');
        Schema::dropIfExists('attendance_penalties');
        Schema::dropIfExists('attendance_overrides');
        Schema::dropIfExists('attendance_shift_overrides');
        Schema::dropIfExists('time_office_requests');
    }
};
