<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity — check-in policy: what a punch carries (photos, face verification, the IP it came from, the
 * WiFi it was on), polygon and time-bound geo locations, time-bound location requests, and location pings for
 * continuous tracking from the mobile app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->json('photos')->nullable()->after('device_identifier')->comment('stored paths of photos taken at the punch');
            $table->boolean('face_verified')->nullable()->after('photos');
            $table->string('ip_address', 45)->nullable()->after('face_verified');
            $table->string('wifi_ssid', 64)->nullable()->after('ip_address');
            $table->string('wifi_bssid', 32)->nullable()->after('wifi_ssid');
        });

        Schema::table('attendance_geo_fences', function (Blueprint $table) {
            $table->string('shape', 16)->default('circle')->after('radius_m')->comment('circle|polygon');
            $table->json('polygon')->nullable()->after('shape')->comment('[[lat, lng], …] when shape = polygon');
            $table->date('valid_from')->nullable()->after('polygon');
            $table->date('valid_until')->nullable()->after('valid_from')->comment('temporary approval ends after this day');
        });

        Schema::table('attendance_location_requests', function (Blueprint $table) {
            $table->date('valid_from')->nullable()->after('radius_m');
            $table->date('valid_until')->nullable()->after('valid_from');
        });

        Schema::create('attendance_location_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->string('device_identifier', 128)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'recorded_at'], 'alp_tenant_employee_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_location_pings');
        Schema::table('attendance_location_requests', fn (Blueprint $table) => $table->dropColumn(['valid_from', 'valid_until']));
        Schema::table('attendance_geo_fences', fn (Blueprint $table) => $table->dropColumn(['shape', 'polygon', 'valid_from', 'valid_until']));
        Schema::table('attendance_punches', fn (Blueprint $table) => $table->dropColumn(['photos', 'face_verified', 'ip_address', 'wifi_ssid', 'wifi_bssid']));
    }
};
