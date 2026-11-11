<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Streamline programme S2 (2026-11-05): the guided payroll run.
 *
 * - Payslips are published in their own step after payroll closes (decision ST-2); months closed before this change
 *   count as published when they closed.
 * - The pre-payroll checklist records who checked each item for a company's pay month and what they saw (ST-3).
 * - The latest closed month can be reopened (ST-1): each reopening is kept with what it withdrew and gave back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_runs', 'payslips_published_at')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->timestamp('payslips_published_at')->nullable()->after('finalized_at');
                $table->foreignId('payslips_published_by')->nullable()->after('payslips_published_at')->constrained('users')->nullOnDelete();
                $table->unsignedSmallInteger('reopen_count')->default(0)->after('payslips_published_by');
            });
            DB::table('payroll_runs')->whereIn('status', ['finalized', 'paid'])->update(['payslips_published_at' => DB::raw('finalized_at')]);
        }

        if (! Schema::hasTable('payroll_prerun_checks')) {
            Schema::create('payroll_prerun_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->string('check_key', 48);
                $table->unsignedInteger('seen_count')->nullable();
                $table->string('seen_signature', 64)->nullable();
                $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('checked_at');
                $table->string('note', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'company_id', 'year', 'month', 'check_key'], 'payroll_prerun_checks_month_key_unique');
            });
        }

        if (! Schema::hasTable('payroll_reopenings')) {
            Schema::create('payroll_reopenings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
                $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('generation');
                $table->string('previous_status', 16);
                $table->string('reason', 500);
                $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('summary');
                $table->json('payslips');
                $table->json('given_back');
                $table->timestamps();

                $table->index(['tenant_id', 'payroll_run_id'], 'payroll_reopenings_run_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_reopenings');
        Schema::dropIfExists('payroll_prerun_checks');
        if (Schema::hasColumn('payroll_runs', 'payslips_published_at')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->dropForeign(['payslips_published_by']);
                $table->dropColumn(['payslips_published_at', 'payslips_published_by', 'reopen_count']);
            });
        }
    }
};
