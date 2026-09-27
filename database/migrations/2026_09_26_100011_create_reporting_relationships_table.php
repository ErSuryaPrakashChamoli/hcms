<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Matrix reporting (blueprint §9): line, functional, dotted, HRBP, mentor, buddy, ...
        Schema::create('reporting_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('employees')->restrictOnDelete();
            $table->string('type', 32)->default('line');
            $table->boolean('is_primary')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'type', 'effective_from'], 'reporting_rel_employee_type_from_idx');
            $table->index(['tenant_id', 'manager_id'], 'reporting_rel_manager_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_relationships');
    }
};
