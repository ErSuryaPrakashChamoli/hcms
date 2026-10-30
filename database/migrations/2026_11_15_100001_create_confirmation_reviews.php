<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity — Workforce → Confirmation policy: a confirmation review per probation end, decided by confirming,
 * extending the probation (within the policy's limits) or terminating; every decision is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('probation_end_date');
            $table->date('due_on');
            $table->string('status', 16)->default('pending')->comment('pending|confirmed|extended|terminated|cancelled');
            $table->unsignedSmallInteger('extension_months')->nullable();
            $table->date('extended_to')->nullable();
            $table->unsignedSmallInteger('extension_number')->default(0)->comment('extensions before this review');
            $table->text('assessment')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('exit_case_id')->nullable()->constrained('exit_cases')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_on'], 'cr_tenant_status_due_idx');
            $table->index(['employee_id', 'status'], 'cr_employee_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmation_reviews');
    }
};
