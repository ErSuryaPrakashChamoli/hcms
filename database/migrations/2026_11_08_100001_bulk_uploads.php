<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk uploads for bank details and monthly attendance.
 *
 * A bank details upload stages its rows encrypted (sealed_data, Laravel's encrypted cast) instead of in the plain
 * data column, because they hold account numbers; the sealed copy is dropped once the upload is run or discarded.
 *
 * attendance_marks hold the day codes of a monthly attendance upload (one per employee and date). They are an input
 * of the attendance engine, like punches and regularisations: the engine applies a mark to the day, approved leave
 * still wins (there is no leave mark: leave comes only from leave requests), and days locked by a finalised payroll
 * run never change. A cell may give each half of the day its own code (first_half, second_half); status is then the
 * day those halves make (one absent half = half_day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_import_rows', function (Blueprint $table) {
            $table->text('sealed_data')->nullable()->after('data');
        });

        Schema::create('attendance_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 16)->comment('present|absent|half_day|weekly_off|holiday|wfh|on_duty|working');
            $table->string('first_half', 16)->nullable()->comment('present|absent|weekly_off|holiday|wfh|on_duty, when the halves differ in the sheet');
            $table->string('second_half', 16)->nullable();
            $table->string('source', 32)->default('upload');
            $table->foreignId('employee_import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'date'], 'attendance_marks_employee_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_marks');

        Schema::table('employee_import_rows', function (Blueprint $table) {
            $table->dropColumn('sealed_data');
        });
    }
};
