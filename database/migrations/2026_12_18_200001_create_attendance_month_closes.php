<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3, "Run attendance"):
 *
 * - attendance_month_closes: one row per company and attendance month once HR closes it (setting
 *   attendance.month_close.enabled, off by default). While `closed`, the month's days refuse requests and overrides like
 *   locked days and keep their calculated values; reopening sets the row back to `open` (who, when and why are kept on
 *   the row and in the audit trail). The dates are the month's window when it was closed.
 * - attendance_month_runs: a queued calculation of a month for a set of employees, with its progress (done / failed of
 *   total) and the person told when it ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_month_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 16)->default('open')->comment('open|closed');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_note', 255)->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'company_id', 'year', 'month'], 'amc_tenant_company_month_uq');
            $table->index(['tenant_id', 'status', 'start_date', 'end_date'], 'amc_tenant_status_dates_idx');
        });

        Schema::create('attendance_month_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 16)->default('running')->comment('running|completed');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('filters')->nullable()->comment('the choices that narrowed the employees (labels only)');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'company_id', 'year', 'month'], 'amr_tenant_company_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_month_runs');
        Schema::dropIfExists('attendance_month_closes');
    }
};
