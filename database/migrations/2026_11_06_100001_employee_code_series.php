<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee code series: prefix + zero-padded number + suffix, one per company plus an optional tenant default
 * (company_id null). The row is its own counter (next_number), moved under a row lock. Tenants without a series keep
 * the settings-based codes (employee.code.prefix / padding, counted in employee_code_sequences).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_code_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('prefix', 16)->default('');
            $table->string('suffix', 16)->default('');
            $table->unsignedTinyInteger('digits')->default(5);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->boolean('allow_manual')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_code_series');
    }
};
