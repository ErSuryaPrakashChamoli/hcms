<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity MS-4 (matrix 22 §22.2.1 #1, #9, #10; §22.2.2 #7; §22.2.3 #8, #9; §22.2.4 #1, #4, #6, #7): person data
 * additions. Every column is nullable or defaults to "off", so existing records read exactly as before.
 *
 * - people.salutation (option list `salutations`; blank = letters derive it from the gender, as before),
 *   people.is_fresher (no earlier employment; refuses experience rows while set), people.personal_phone_country_code.
 * - employees.work_phone_country_code; employees.device_user_id (the id a biometric device knows the employee by; unique
 *   per tenant; PunchIngestion tries it before the employee code — blank = matched by code, as before).
 * - person_emergency_contacts.phone_country_code. A phone with a country code is stored in E.164; without one, as typed.
 * - person_family_members.in_school, in_hostel (default false), remarks.
 * - person_qualifications.is_highest (one per person), mode (option list `qualification_modes`), year_started.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('salutation', 16)->nullable()->after('tenant_id');
            $table->boolean('is_fresher')->default(false)->after('blood_group');
            $table->string('personal_phone_country_code', 4)->nullable()->after('personal_email');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('work_phone_country_code', 4)->nullable()->after('work_email');
            $table->string('device_user_id', 64)->nullable()->after('employee_code');
            $table->unique(['tenant_id', 'device_user_id'], 'employees_tenant_device_user_unique');
        });

        Schema::table('person_emergency_contacts', function (Blueprint $table) {
            $table->string('phone_country_code', 4)->nullable()->after('relation');
        });

        Schema::table('person_family_members', function (Blueprint $table) {
            $table->boolean('in_school')->default(false)->after('nominee_share');
            $table->boolean('in_hostel')->default(false)->after('in_school');
            $table->string('remarks', 255)->nullable()->after('phone');
        });

        Schema::table('person_qualifications', function (Blueprint $table) {
            $table->boolean('is_highest')->default(false)->after('grade_or_score');
            $table->string('mode', 32)->nullable()->after('is_highest');
            $table->unsignedSmallInteger('year_started')->nullable()->after('board_or_university');
        });
    }

    public function down(): void
    {
        Schema::table('person_qualifications', function (Blueprint $table) {
            $table->dropColumn(['is_highest', 'mode', 'year_started']);
        });
        Schema::table('person_family_members', function (Blueprint $table) {
            $table->dropColumn(['in_school', 'in_hostel', 'remarks']);
        });
        Schema::table('person_emergency_contacts', function (Blueprint $table) {
            $table->dropColumn('phone_country_code');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_tenant_device_user_unique');
            $table->dropColumn(['work_phone_country_code', 'device_user_id']);
        });
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['salutation', 'is_fresher', 'personal_phone_country_code']);
        });
    }
};
