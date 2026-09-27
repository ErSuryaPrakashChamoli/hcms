<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 5.4 — statutory output control layer (Parts M, N, O, P, Q, V) and EPF / ECR (Part H).
 |
 | statutory_returns is the lifecycle header shared by every return type (EPF, ESI, PT, LWF, TDS):
 | status, separation-of-duties actors, filing references and a nullable `uniqueness_key` that
 | enforces one live return per scope, period, kind and sequence (cleared on cancellation).
 | Type-specific content lives in the per-type tables (epf_return_runs / epf_return_entries ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->restrictOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('return_type', 16);
            $table->string('form_code', 32);
            $table->string('legacy_form_code', 32)->nullable();
            $table->string('return_kind', 16)->default('regular');
            $table->string('state_code', 8)->nullable();
            $table->string('period_key', 24);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->foreignId('parent_return_id')->nullable()->constrained('statutory_returns')->restrictOnDelete();
            $table->string('status', 32)->default('draft');
            $table->string('uniqueness_key', 191)->nullable()->unique();
            $table->json('rule_versions')->nullable();
            $table->json('payroll_run_ids')->nullable();
            $table->string('format_code', 32)->nullable();
            $table->string('format_version', 32)->nullable();
            $table->string('format_verification_status', 16)->nullable();
            $table->json('totals')->nullable();
            $table->json('validation')->nullable();
            $table->unsignedInteger('blocking_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->string('reconciliation_status', 32)->nullable();
            $table->json('attestations')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at')->nullable();
            $table->string('export_filename')->nullable();
            $table->string('export_path')->nullable();
            $table->string('export_checksum', 64)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->string('external_reference', 128)->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_reference', 128)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->string('operation_id', 32)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'return_type', 'status']);
            $table->index(['establishment_id', 'return_type', 'period_key'], 'statutory_returns_scope_idx');
            $table->index(['legal_entity_id', 'return_type', 'period_key'], 'statutory_returns_entity_idx');
        });

        Schema::create('statutory_return_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statutory_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 24);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 1000)->nullable();
            $table->string('source', 16)->default('ui');
            $table->string('version', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['statutory_return_id', 'id']);
        });

        Schema::create('statutory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statutory_return_id')->constrained()->restrictOnDelete();
            $table->string('entry_type');
            $table->unsignedBigInteger('entry_id');
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('tax_regime', 8)->nullable();
            $table->foreignId('compliance_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('rule_checksum', 64)->nullable();
            $table->json('inputs')->nullable();
            $table->json('calculated')->nullable();
            $table->json('output')->nullable();
            $table->string('checksum', 64);
            $table->foreignId('supersedes_id')->nullable()->constrained('statutory_snapshots')->restrictOnDelete();
            $table->string('reason', 1000)->nullable();
            $table->timestamp('captured_at');

            $table->unique(['entry_type', 'entry_id'], 'statutory_snapshots_entry_unique');
            $table->index(['statutory_return_id', 'employee_id']);
        });

        Schema::create('statutory_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statutory_return_id')->constrained()->restrictOnDelete();
            $table->string('stage', 16);
            $table->string('status', 16);
            $table->json('checks');
            $table->unsignedInteger('blocking_count')->default(0);
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');

            $table->index(['statutory_return_id', 'id']);
        });

        Schema::create('epf_return_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statutory_return_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('establishment_id')->constrained()->restrictOnDelete();
            $table->foreignId('statutory_registration_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $table->date('wage_month');
            $table->string('return_kind', 16);
            $table->unsignedInteger('member_count')->default(0);
            $table->json('totals')->nullable();
            $table->timestamps();

            $table->index(['establishment_id', 'wage_month']);
        });

        Schema::create('epf_return_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('epf_return_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('statutory_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->text('uan')->nullable();
            $table->string('uan_hash', 64)->nullable();
            $table->string('uan_last4', 4)->nullable();
            $table->string('member_name');
            // Calculated bases (from finalized payroll lines), kept separate from exported values.
            $table->decimal('calc_gross_wages', 14, 2)->default(0);
            $table->decimal('calc_epf_wages', 14, 2)->default(0);
            $table->decimal('calc_eps_wages', 14, 2)->default(0);
            $table->decimal('calc_edli_wages', 14, 2)->default(0);
            $table->decimal('calc_ee_share', 14, 2)->default(0);
            $table->decimal('calc_eps_share', 14, 2)->default(0);
            $table->decimal('calc_er_share', 14, 2)->default(0);
            $table->decimal('calc_ncp_days', 6, 2)->default(0);
            $table->decimal('calc_refund_of_advances', 14, 2)->default(0);
            $table->unsignedBigInteger('export_gross_wages')->default(0);
            $table->unsignedBigInteger('export_epf_wages')->default(0);
            $table->unsignedBigInteger('export_eps_wages')->default(0);
            $table->unsignedBigInteger('export_edli_wages')->default(0);
            $table->unsignedBigInteger('export_ee_share')->default(0);
            $table->unsignedBigInteger('export_eps_share')->default(0);
            $table->unsignedBigInteger('export_er_share')->default(0);
            $table->unsignedSmallInteger('export_ncp_days')->default(0);
            $table->unsignedBigInteger('export_refund_of_advances')->default(0);
            $table->foreignId('compliance_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('rule_checksum', 64)->nullable();
            $table->string('status', 16)->default('ok');
            $table->json('issues')->nullable();
            $table->timestamps();

            $table->unique(['epf_return_run_id', 'employee_id']);
            $table->unique(['epf_return_run_id', 'uan_hash']);
        });

        Schema::create('epf_return_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('original_return_id')->constrained('statutory_returns')->restrictOnDelete();
            $table->foreignId('revised_return_id')->unique()->constrained('statutory_returns')->restrictOnDelete();
            $table->string('reason', 1000);
            $table->string('direction', 16);
            $table->boolean('payment_not_initiated_attested')->default(false);
            $table->foreignId('attested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epf_return_revisions');
        Schema::dropIfExists('epf_return_entries');
        Schema::dropIfExists('epf_return_runs');
        Schema::dropIfExists('statutory_reconciliations');
        Schema::dropIfExists('statutory_snapshots');
        Schema::dropIfExists('statutory_return_actions');
        Schema::dropIfExists('statutory_returns');
    }
};
