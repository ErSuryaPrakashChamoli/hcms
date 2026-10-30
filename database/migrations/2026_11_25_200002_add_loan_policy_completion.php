<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity BP-9 (loan policy completion): the limits a request was let through beyond (`exceptions`, when the
 * policy allows exceptions), what the employee asked for when the approver changed it (`requested_terms`), and every
 * guarantor of a loan (`loan_guarantors`; `loans.guarantor_employee_id` keeps the first one, as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->json('exceptions')->nullable()->after('reason')->comment('policy limits the request is beyond, for the approver');
            $table->json('requested_terms')->nullable()->after('exceptions')->comment('principal, instalments, first month and rate as asked, when the approver changed them');
        });

        Schema::create('loan_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending')->comment('pending|accepted|declined');
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'employee_id'], 'loan_guarantors_loan_employee_unique');
            $table->index(['tenant_id', 'employee_id', 'status'], 'loan_guarantors_tenant_employee_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_guarantors');
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['exceptions', 'requested_terms']);
        });
    }
};
