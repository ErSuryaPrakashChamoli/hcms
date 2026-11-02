<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-1 (Integration → JV mappings): the accounting (journal voucher) code of a PeopleOS record, per
 * dimension (company, branch, department, employee …) and optionally per accounting system. Unlike integration_mappings
 * (one external value → one record, unique per system and dimension), many records may share one JV code (several
 * departments post to one ledger), so the key is the record: one code per dimension, record and system.
 * system_key is the system id or '' (no system), so the unique key also holds without a system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jv_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 32);
            $table->string('record_type', 32)->comment('company|location|business_unit|department|designation|dimension_value|employee|grade|level|region');
            $table->unsignedBigInteger('record_id');
            $table->foreignId('integration_system_id')->nullable()->constrained('integration_systems', indexName: 'jv_codes_system_fk')->restrictOnDelete();
            $table->string('system_key', 20)->default('');
            $table->string('code', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'dimension', 'record_id', 'system_key'], 'jv_codes_record_system_unique');
            $table->index(['tenant_id', 'dimension', 'code'], 'jv_codes_dimension_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jv_codes');
    }
};
