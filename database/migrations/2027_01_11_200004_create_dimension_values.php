<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-1 (Core → Dimension): the values of the organisation's own extra dimension (HROne's "Dimension",
 * e.g. verticals or practices), optionally nested under another value. Employees are not assigned to a value yet
 * (an open owner decision); the values carry JV codes (Integration → JV dimension mapping).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dimension_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->foreignId('parent_id')->nullable()->constrained('dimension_values', indexName: 'dimension_values_parent_fk')->restrictOnDelete();
            $table->string('description', 1000)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'dimension_values_tenant_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dimension_values');
    }
};
