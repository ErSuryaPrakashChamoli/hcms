<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->foreignId('level_id')->nullable()->constrained('levels')->restrictOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained('grades')->restrictOnDelete();
            $table->foreignId('job_family_id')->nullable()->constrained('job_families')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('default_reporting_level_id')->nullable()->constrained('levels')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('designation_employment_type', function (Blueprint $table) {
            $table->foreignId('designation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employment_type_id')->constrained()->cascadeOnDelete();

            $table->primary(['designation_id', 'employment_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('designation_employment_type');
        Schema::dropIfExists('designations');
    }
};
