<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity HP-2: salary holds (PY-06: a month's pay kept out of the bank file until released) and variable pay
 * requests (PY-05: an incentive or bonus requested for a pay month, approved, then paid through payroll).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('pay_month', 7)->comment('Y-m of the payroll month held');
            $table->string('reason', 500);
            $table->string('status', 16)->default('held')->comment('held|released');
            $table->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_note', 500)->nullable();
            $table->string('release_reference', 120)->nullable()->comment('payment reference when paid outside the bank file');
            $table->timestamps();

            $table->index(['tenant_id', 'pay_month', 'status'], 'sh_tenant_month_status_idx');
        });

        Schema::create('variable_pay_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('pay_month', 7);
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending')->comment('pending|approved|rejected|paid|cancelled');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->unsignedBigInteger('payroll_run_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status'], 'vpr_tenant_employee_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variable_pay_requests');
        Schema::dropIfExists('salary_holds');
    }
};
