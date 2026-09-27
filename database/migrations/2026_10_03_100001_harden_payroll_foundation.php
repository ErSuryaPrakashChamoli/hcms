<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4: additive payroll hardening — run versioning and reconciliation, adjustment approval and arrears sources. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('calculation_version', 32)->nullable()->after('status');
            $table->json('rule_versions')->nullable()->after('totals');
            $table->json('reconciliation')->nullable()->after('rule_versions');
            $table->string('operation_id', 26)->nullable()->after('reconciliation');
        });

        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->string('status', 16)->default('approved')->after('taxable')->comment('pending|approved|rejected');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('source_type')->nullable()->after('approved_at');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->foreignId('reference_period_id')->nullable()->after('source_id')->constrained('payroll_periods')->nullOnDelete()->comment('period an arrear or recovery relates to');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('reference_period_id');
            $table->dropColumn(['status', 'approved_at', 'source_type', 'source_id']);
        });
        Schema::table('payroll_runs', fn (Blueprint $table) => $table->dropColumn(['calculation_version', 'rule_versions', 'reconciliation', 'operation_id']));
    }
};
