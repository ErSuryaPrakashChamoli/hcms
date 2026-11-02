<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity MS-5 (matrix 22 §22.2.3 #3, §22.2.5 #1–7): an employee's health insurance.
 *
 * - employee_insurance_policies: one row per policy the employee is covered by (insurer name and code, policy number,
 *   sum assured, issue date, valid until, the e-card file on the documents disk). One policy number per employee.
 *   `valid_until` feeds the daily lifecycle reminders.
 * - insured_members: who a policy covers — the employee (`member_key` "self") or one of the person's family members
 *   (`member_key` "family:{id}"), each once per policy — with the insurer's member id (encrypted at rest: text for the
 *   ciphertext; `member_id_last4` keeps the masked form for lists), relation, date of birth, gender and whether the
 *   member is under the parents' cover. `employee_id` repeats the policy's employee so organisation scope applies.
 *
 * Index and foreign key names are explicit (MySQL's 64-character limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees', indexName: 'emp_insurance_policies_employee_foreign')->cascadeOnDelete();
            $table->string('insurer_name', 255);
            $table->string('insurer_code', 64)->nullable();
            $table->string('policy_number', 64);
            $table->decimal('sum_assured', 14, 2)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('ecard_path', 500)->nullable();
            $table->string('ecard_name', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'policy_number'], 'emp_insurance_policies_employee_policy_unique');
            $table->index(['tenant_id', 'valid_until'], 'emp_insurance_policies_valid_until_index');
        });

        Schema::create('insured_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('employee_insurance_policies', indexName: 'insured_members_policy_foreign')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees', indexName: 'insured_members_employee_foreign')->cascadeOnDelete();
            $table->foreignId('family_member_id')->nullable()->constrained('person_family_members', indexName: 'insured_members_family_member_foreign')->cascadeOnDelete();
            $table->string('member_key', 32);
            $table->string('name', 255);
            $table->string('relation', 32);
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->text('member_id')->nullable();
            $table->string('member_id_last4', 8)->nullable();
            $table->boolean('is_parents_cover')->default(false);
            $table->timestamps();

            $table->unique(['policy_id', 'member_key'], 'insured_members_policy_member_unique');
            $table->index(['tenant_id', 'employee_id'], 'insured_members_tenant_employee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insured_members');
        Schema::dropIfExists('employee_insurance_policies');
    }
};
