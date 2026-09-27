<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 5.6 — state-aware professional tax (Part J) and labour welfare fund (Part K) returns,
 | per establishment, state and month. The state always comes from the establishment.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['professional_tax_returns' => 'professional_tax_return_entries', 'lwf_returns' => 'lwf_return_entries'] as $runs => $entries) {
            Schema::create($runs, function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('statutory_return_id')->unique()->constrained()->restrictOnDelete();
                $table->foreignId('establishment_id')->constrained()->restrictOnDelete();
                $table->foreignId('statutory_registration_id')->nullable()->constrained()->nullOnDelete();
                $table->string('state_code', 8);
                $table->date('return_month');
                $table->unsignedInteger('member_count')->default(0);
                $table->json('totals')->nullable();
                $table->timestamps();

                $table->index(['establishment_id', 'return_month']);
            });
        }

        Schema::create('professional_tax_return_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_tax_return_id')->constrained(indexName: 'pt_return_entries_return_fk')->restrictOnDelete();
            $table->foreignId('statutory_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('member_name');
            $table->string('state_code', 8)->nullable();
            $table->string('state_source', 32)->nullable();
            $table->decimal('calc_gross', 14, 2)->default(0);
            $table->decimal('calc_pt_amount', 14, 2)->default(0);
            $table->decimal('export_pt_amount', 14, 2)->default(0);
            $table->foreignId('compliance_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('rule_checksum', 64)->nullable();
            $table->string('status', 16)->default('ok');
            $table->json('issues')->nullable();
            $table->timestamps();

            $table->unique(['professional_tax_return_id', 'employee_id'], 'pt_return_entries_employee_unique');
        });

        Schema::create('lwf_return_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lwf_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('statutory_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('member_name');
            $table->string('state_code', 8)->nullable();
            $table->decimal('calc_gross', 14, 2)->default(0);
            $table->decimal('calc_ee_contribution', 14, 2)->default(0);
            $table->decimal('calc_er_contribution', 14, 2)->default(0);
            $table->decimal('export_ee_contribution', 14, 2)->default(0);
            $table->decimal('export_er_contribution', 14, 2)->default(0);
            $table->foreignId('compliance_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('rule_checksum', 64)->nullable();
            $table->string('status', 16)->default('ok');
            $table->json('issues')->nullable();
            $table->timestamps();

            $table->unique(['lwf_return_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lwf_return_entries');
        Schema::dropIfExists('professional_tax_return_entries');
        Schema::dropIfExists('lwf_returns');
        Schema::dropIfExists('professional_tax_returns');
    }
};
