<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One employee record per person per tenant; org placement lives in employee_positions.
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_code', 32);
            $table->string('lifecycle_state', 32)->default('pre_employee');
            $table->date('joining_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('confirmation_date')->nullable();
            $table->date('exit_date')->nullable();
            $table->string('work_email')->nullable();
            $table->string('work_phone', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_code']);
            $table->unique(['tenant_id', 'person_id']);
            $table->index(['tenant_id', 'lifecycle_state']);
            $table->index(['tenant_id', 'work_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
