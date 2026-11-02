<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity TX-12 (matrix 24 §24.7.2, §24.8 #10 — "Manage holiday" / "Change holiday"; owner decision TIM-3 / D-TX3):
 *
 * employee_holiday_changes — HR's dated exceptions to an employee's holiday calendar, one row per day they change. Effect
 * `add` makes the day a holiday for the employee (name, type, restricted flag; picked from a holiday calendar or the
 * holiday library, or typed); effect `remove` makes a calendar holiday an ordinary day for them. What HR chose is
 * `action`: assign (one add row), remove (one remove row) or replace (a remove row and an add row sharing `group_key`).
 * HolidayResolver reads the live row of the day after the calendar and it wins (D-TX3). Rows are never deleted: undoing a
 * change marks its rows undone with who, when and why, and `active` becomes null — the unique key allows one live change
 * per employee and day. No row exists by default, so every holiday comes from the calendars exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_holiday_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->char('group_key', 26)->comment('the rows one change made for the employee (a replace has two)');
            $table->string('action', 8)->comment('assign|remove|replace');
            $table->string('effect', 8)->comment('add|remove');
            $table->date('date');
            $table->string('name', 150)->comment('add: the holiday given; remove: the calendar holiday taken away');
            $table->string('holiday_type', 16)->nullable()->comment('public|company|optional');
            $table->boolean('is_restricted')->default(false);
            $table->string('source', 16)->comment('typed|calendar|library');
            $table->foreignId('holiday_id')->nullable()->constrained('holidays')->nullOnDelete();
            $table->foreignId('holiday_library_entry_id')->nullable()->constrained('holiday_library_entries')->nullOnDelete();
            $table->string('reason', 500);
            $table->string('status', 12)->default('active')->comment('active|undone');
            $table->boolean('active')->nullable()->default(true)->comment('1 while live, null once undone');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->string('undo_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'date', 'active'], 'ehc_employee_day_active_uq');
            $table->index(['tenant_id', 'date'], 'ehc_tenant_date_idx');
            $table->index(['tenant_id', 'group_key'], 'ehc_tenant_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_holiday_changes');
    }
};
