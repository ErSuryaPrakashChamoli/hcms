<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 5.7 — TDS on salary (Part L), separate from the monthly payroll deduction:
 |   tds_profiles           deductor details per legal entity (TAN, responsible person)
 |   tds_financial_years    per legal entity and FY: open/closed, ledger verification
 |   tds_employee_investments  investment proofs against employee_tax_declarations (which already
 |                          serves as the declaration table; no duplicate is created)
 |   tds_annual_ledgers     one immutable row per finalized payroll entry (superseded, never edited)
 |   tds_quarterly_returns / tds_quarterly_return_entries   Form No. 138 (earlier Form 24Q)
 |   tds_certificates       Form No. 130 (earlier Form 16), snapshot from the verified ledger
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tds_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tan_registration_id')->nullable()->constrained('statutory_registrations')->nullOnDelete();
            $table->foreignId('pan_registration_id')->nullable()->constrained('statutory_registrations')->nullOnDelete();
            $table->string('deductor_category', 32)->default('company');
            $table->string('responsible_person_name')->nullable();
            $table->string('responsible_person_designation')->nullable();
            $table->text('responsible_person_pan')->nullable();
            $table->string('responsible_person_pan_last4', 4)->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('tds_financial_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->string('financial_year', 9);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 16)->default('open');
            $table->timestamp('ledger_verified_at')->nullable();
            $table->foreignId('ledger_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ledger_checksum', 64)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'financial_year']);
        });

        Schema::create('tds_employee_investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_tax_declaration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('financial_year', 9);
            $table->string('section', 16);
            $table->string('description')->nullable();
            $table->decimal('declared_amount', 14, 2)->default(0);
            $table->decimal('proof_amount', 14, 2)->nullable();
            $table->string('proof_status', 16)->default('pending');
            $table->foreignId('employee_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'financial_year']);
        });

        Schema::create('tds_annual_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('financial_year', 9);
            $table->date('month');
            $table->unsignedTinyInteger('quarter');
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_key', 64);
            $table->string('active_source_key', 64)->nullable()->unique();
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('taxable_earnings', 14, 2)->default(0);
            $table->decimal('tds_deducted', 14, 2)->default(0);
            $table->string('tax_regime', 8)->nullable();
            $table->boolean('pan_available')->default(false);
            $table->foreignId('compliance_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('rule_checksum', 64)->nullable();
            $table->json('basis')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('superseded_by_id')->nullable()->constrained('tds_annual_ledgers')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['legal_entity_id', 'financial_year', 'employee_id'], 'tds_ledger_lookup_idx');
        });

        Schema::create('tds_quarterly_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statutory_return_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->restrictOnDelete();
            $table->foreignId('tan_registration_id')->nullable()->constrained('statutory_registrations')->nullOnDelete();
            $table->string('financial_year', 9);
            $table->unsignedTinyInteger('quarter');
            $table->string('form_code', 16)->default('FORM_138');
            $table->string('legacy_form_code', 16)->default('FORM_24Q');
            $table->json('challans')->nullable();
            $table->json('annexure_ii')->nullable();
            $table->unsignedInteger('deductee_count')->default(0);
            $table->json('totals')->nullable();
            $table->timestamps();

            $table->index(['legal_entity_id', 'financial_year', 'quarter'], 'tds_quarterly_lookup_idx');
        });

        Schema::create('tds_quarterly_return_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tds_quarterly_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('statutory_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('tds_annual_ledger_id')->constrained()->restrictOnDelete();
            $table->text('pan')->nullable();
            $table->string('pan_hash', 64)->nullable();
            $table->string('pan_last4', 4)->nullable();
            $table->string('member_name');
            $table->string('section_code', 16)->nullable();
            $table->date('payment_date');
            $table->decimal('calc_amount_paid', 14, 2)->default(0);
            $table->decimal('calc_tax_deducted', 14, 2)->default(0);
            $table->decimal('export_amount_paid', 14, 2)->default(0);
            $table->decimal('export_tax_deducted', 14, 2)->default(0);
            $table->string('reason_code', 8)->nullable();
            $table->foreignId('compliance_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('rule_checksum', 64)->nullable();
            $table->string('status', 16)->default('ok');
            $table->json('issues')->nullable();
            $table->timestamps();

            $table->unique(['tds_quarterly_return_id', 'tds_annual_ledger_id'], 'tds_q_entries_ledger_unique');
        });

        Schema::create('tds_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('tds_financial_year_id')->constrained()->restrictOnDelete();
            $table->string('financial_year', 9);
            $table->string('code', 16)->default('FORM_130');
            $table->string('legacy_code', 16)->default('FORM_16');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 16)->default('generated');
            $table->string('certificate_number', 64)->nullable();
            $table->json('snapshot');
            $table->string('ledger_checksum', 64);
            $table->string('snapshot_checksum', 64);
            $table->foreignId('supersedes_id')->nullable()->constrained('tds_certificates')->nullOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'legal_entity_id', 'financial_year', 'version'], 'tds_certificates_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_certificates');
        Schema::dropIfExists('tds_quarterly_return_entries');
        Schema::dropIfExists('tds_quarterly_returns');
        Schema::dropIfExists('tds_annual_ledgers');
        Schema::dropIfExists('tds_employee_investments');
        Schema::dropIfExists('tds_financial_years');
        Schema::dropIfExists('tds_profiles');
    }
};
