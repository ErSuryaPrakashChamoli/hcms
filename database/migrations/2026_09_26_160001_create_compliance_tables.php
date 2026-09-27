<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-owned statutory rules (§32). No tenant_id: every tenant reads the same versions
        // and nobody but the platform can write them (protected statutory safeguards, §101).
        Schema::create('compliance_rules', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 4)->default('IN');
            $table->string('code', 32);
            $table->string('state', 4)->nullable();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('parameters');
            $table->string('source')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['jurisdiction', 'code', 'state', 'version'], 'compliance_rules_identity_unique');
            $table->index(['jurisdiction', 'code', 'state', 'effective_from'], 'compliance_rules_lookup_idx');
        });

        // Per legal entity registrations and applicability (§7, §8, §32).
        Schema::create('company_statutory_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('jurisdiction', 4)->default('IN');
            $table->string('pt_state', 4)->nullable();
            $table->string('lwf_state', 4)->nullable();
            $table->boolean('pf_applicable')->default(true);
            $table->boolean('esi_applicable')->default(true);
            $table->boolean('pt_applicable')->default(true);
            $table->boolean('lwf_applicable')->default(false);
            $table->boolean('tds_applicable')->default(true);
            $table->boolean('pf_restrict_to_ceiling')->default(true);
            $table->string('pf_establishment_code', 64)->nullable();
            $table->string('esi_code', 64)->nullable();
            $table->string('pt_registration', 64)->nullable();
            $table->string('tan', 16)->nullable();
            $table->string('pan', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('employee_tax_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('financial_year', 9);
            $table->string('regime', 8)->default('new');
            $table->json('declarations')->nullable();
            $table->decimal('previous_employer_income', 14, 2)->default(0);
            $table->decimal('previous_employer_tds', 14, 2)->default(0);
            $table->string('status', 32)->default('draft');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'financial_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tax_declarations');
        Schema::dropIfExists('company_statutory_profiles');
        Schema::dropIfExists('compliance_rules');
    }
};
