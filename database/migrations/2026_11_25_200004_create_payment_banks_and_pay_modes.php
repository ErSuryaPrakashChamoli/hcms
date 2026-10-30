<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity BP-13 / CA-5 (salary bank, payment banks, pay modes):
 * - bank_file_layouts: versioned bank-specific salary-file formats, as data (draft → active → retired; frozen and
 *   checksummed once active);
 * - company_bank_accounts: the accounts a company pays salaries from (debit account encrypted, last four kept), each
 *   naming its file layout by code;
 * - employee_payment_modes: how an employee is paid from a date (bank transfer / cash / cheque, optionally a paying
 *   account; reimbursements the same or differently). No row = bank transfer, as before;
 * - bank_account_rules: what employees' account numbers at a bank look like (length, characters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_file_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->unsignedInteger('version')->default(1);
            $table->string('name', 120);
            $table->string('status', 16)->default('draft')->comment('draft|active|retired');
            $table->json('specification');
            $table->string('checksum', 64);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code', 'version'], 'bank_file_layouts_tenant_code_version_unique');
        });

        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 64)->nullable();
            $table->string('bank_name', 120);
            $table->string('bank_code', 32)->nullable()->comment('the salary bank code the bank gives the company');
            $table->string('branch_name', 120)->nullable();
            $table->string('ifsc', 16)->nullable();
            $table->text('account_number')->comment('encrypted');
            $table->string('account_number_last4', 4)->nullable();
            $table->string('bank_file_layout_code', 32)->nullable()->comment('null = the plain salary file');
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('active');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'company_id', 'status'], 'company_bank_accounts_tenant_company_status_idx');
        });

        Schema::create('employee_payment_modes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->string('salary_mode', 16)->default('online_transfer')->comment('online_transfer|cash|cheque');
            $table->foreignId('salary_bank_account_id')->nullable()->constrained('company_bank_accounts')->restrictOnDelete();
            $table->string('reimbursement_mode', 16)->nullable()->comment('null = as the salary');
            $table->foreignId('reimbursement_bank_account_id')->nullable()->constrained('company_bank_accounts')->restrictOnDelete();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'effective_from'], 'employee_payment_modes_employee_from_idx');
        });

        Schema::create('bank_account_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name', 120)->nullable();
            $table->string('ifsc_prefix', 4)->nullable()->comment('the bank part of the IFSC, e.g. HDFC');
            $table->unsignedTinyInteger('min_length')->nullable();
            $table->unsignedTinyInteger('max_length')->nullable();
            $table->boolean('allow_digits')->default(true);
            $table->boolean('allow_letters')->default(true);
            $table->boolean('allow_special')->default(false);
            $table->string('status', 16)->default('active');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'bank_account_rules_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_rules');
        Schema::dropIfExists('employee_payment_modes');
        Schema::dropIfExists('company_bank_accounts');
        Schema::dropIfExists('bank_file_layouts');
    }
};
