<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 5.2 — statutory registrations (first-class, extensible), employee ↔ establishment
 | assignments (effective-dated, immutable history), establishment statutory profiles (applicability
 | per statute and period; never rates), and the establishment a payroll entry was calculated for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('statutory_authority', 32);
            $table->string('registration_type', 48);
            $table->text('registration_number');
            $table->string('registration_number_hash', 64);
            $table->string('registration_number_last4', 8)->nullable();
            $table->string('registration_name')->nullable();
            $table->string('jurisdiction', 128)->nullable();
            $table->string('state_code', 8)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->json('metadata')->nullable();
            $table->string('verification_status', 16)->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'registration_type', 'registration_number_hash', 'effective_from'], 'statutory_registrations_identity_unique');
            $table->index(['establishment_id', 'registration_type', 'effective_from'], 'statutory_registrations_lookup_idx');
            $table->index(['legal_entity_id', 'registration_type']);
        });

        Schema::create('employee_establishment_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('assignment_reason', 500);
            $table->string('source', 16)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('closure_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from'], 'emp_establishment_assignments_start_unique');
            $table->index(['establishment_id', 'effective_from'], 'emp_establishment_assignments_lookup_idx');
        });

        Schema::create('establishment_statutory_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->string('statute', 8);
            $table->boolean('applicable')->default(true);
            $table->foreignId('statutory_registration_id')->nullable()->constrained('statutory_registrations', indexName: 'est_statutory_profiles_registration_fk')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['establishment_id', 'statute', 'effective_from'], 'establishment_statutory_profiles_unique');
        });

        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->foreignId('legal_entity_id')->nullable()->after('employee_salary_assignment_id')->constrained()->nullOnDelete();
            $table->foreignId('establishment_id')->nullable()->after('legal_entity_id')->constrained()->nullOnDelete();
            $table->index(['payroll_run_id', 'establishment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropIndex(['payroll_run_id', 'establishment_id']);
            $table->dropConstrainedForeignId('establishment_id');
            $table->dropConstrainedForeignId('legal_entity_id');
        });
        Schema::dropIfExists('establishment_statutory_profiles');
        Schema::dropIfExists('employee_establishment_assignments');
        Schema::dropIfExists('statutory_registrations');
    }
};
