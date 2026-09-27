<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 5.5 — ESI monthly contribution returns (Part I), per establishment and month, inside an
 | ESI contribution period. Content tables of a statutory_returns header.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esi_return_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statutory_return_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('establishment_id')->constrained()->restrictOnDelete();
            $table->foreignId('statutory_registration_id')->nullable()->constrained()->nullOnDelete();
            $table->date('contribution_month');
            $table->string('contribution_period', 24);
            $table->unsignedInteger('member_count')->default(0);
            $table->json('totals')->nullable();
            $table->timestamps();

            $table->index(['establishment_id', 'contribution_month']);
        });

        Schema::create('esi_return_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('esi_return_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('statutory_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->text('ip_number')->nullable();
            $table->string('ip_number_hash', 64)->nullable();
            $table->string('ip_number_last4', 4)->nullable();
            $table->string('member_name');
            $table->string('contribution_period', 24);
            $table->decimal('calc_days', 6, 2)->default(0);
            $table->decimal('calc_wages', 14, 2)->default(0);
            $table->decimal('calc_ee_contribution', 14, 2)->default(0);
            $table->decimal('calc_er_contribution', 14, 2)->default(0);
            $table->unsignedSmallInteger('export_days')->default(0);
            $table->decimal('export_wages', 14, 2)->default(0);
            $table->foreignId('compliance_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('rule_checksum', 64)->nullable();
            $table->string('status', 16)->default('ok');
            $table->json('issues')->nullable();
            $table->timestamps();

            $table->unique(['esi_return_run_id', 'employee_id']);
            $table->unique(['esi_return_run_id', 'ip_number_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esi_return_entries');
        Schema::dropIfExists('esi_return_runs');
    }
};
