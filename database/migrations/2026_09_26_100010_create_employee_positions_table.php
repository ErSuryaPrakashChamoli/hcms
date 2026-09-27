<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Effective-dated organisational assignment (blueprint §70, §100). Never updated in
        // place for a real change: a new row is opened and the previous one closed.
        Schema::create('employee_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employee_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('work_mode_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('change_type', 32)->default('hire');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'effective_from']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_positions');
    }
};
