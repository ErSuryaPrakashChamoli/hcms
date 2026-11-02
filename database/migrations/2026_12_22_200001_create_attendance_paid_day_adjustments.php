<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity TX-4 (matrix 24 §24.5.3 #18–21, §24.5.4 #6; owner decision TIM-2 / D-TX2):
 *
 * attendance_paid_day_adjustments — HR's change to an employee's paid days for one attendance month (company, year,
 * month as the attendance month desk keys months), behind attendance.paid_day_adjustments.enabled (off by default: no row
 * has any effect). Mode `set` fixes the counts of present days, paid leave, holidays and weekly offs (or the total in
 * `value`); mode `reduce` takes none / a share / all of chosen categories. The dates are the month's window when the
 * adjustment was saved; paid_days_before / after are what the preview showed then. Rows are never deleted: a reversal (or
 * a newer adjustment for the same month) marks the row reversed with who, when and why. `active` is 1 while the row
 * counts and null once reversed, so the unique key allows one counting adjustment per employee, company and month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_paid_day_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('mode', 8)->comment('set|reduce');
            $table->decimal('value', 6, 2)->nullable()->comment('set: the paid days set (sum of the counts)');
            $table->json('categories')->nullable()->comment('set: count per category; reduce: {how: none|partial|full, percent} per category');
            $table->decimal('paid_days_before', 6, 2)->default(0);
            $table->decimal('paid_days_after', 6, 2)->default(0);
            $table->string('rounding', 16)->nullable()->comment('the attendance policy rounding used for partial reductions');
            $table->string('reason', 255);
            $table->string('status', 16)->default('active')->comment('active|reversed');
            $table->boolean('active')->nullable()->default(true)->comment('1 while counting, null once reversed');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'company_id', 'year', 'month', 'active'], 'apda_active_month_uq');
            $table->index(['tenant_id', 'company_id', 'year', 'month'], 'apda_tenant_company_month_idx');
            $table->index(['tenant_id', 'employee_id', 'start_date', 'end_date'], 'apda_tenant_employee_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_paid_day_adjustments');
    }
};
