<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import (Workforce → Contract): the contract terms of contract staff — the agency (vendor) that supplies them, the
 * contract number and its dates. One employee can hold several contracts over time (renewals); a contract number is
 * unique per employee. Named to run after vendors (2027_01_21_200001): the agency is a vendor. Databases that already
 * created the table under its first name (2027_01_16_200001) keep it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_contracts')) {
            return;
        }
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('contract_number', 64);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'contract_number'], 'employee_contracts_employee_number_unique');
            $table->index(['tenant_id', 'vendor_id'], 'employee_contracts_vendor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }
};
