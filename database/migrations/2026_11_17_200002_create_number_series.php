<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity (Forms & templates → Code series): how a tenant numbers its loans, letters (all, or one letter
 * type), tickets, exit cases and alumni requests, for the whole organisation (company_id null) or one company. A code
 * type without a row keeps its built-in format, which is exactly the format used before. Numbers are counted in the
 * existing locked number_sequences rows, one per rendered pattern, so a series never reissues a number.
 *
 * Employee code series gain an optional employment type: company + type, then company, then type, then the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('prefix', 24)->default('');
            $table->string('suffix', 16)->default('');
            $table->unsignedTinyInteger('digits')->default(5);
            $table->unsignedBigInteger('start_number')->default(1);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'key', 'company_id'], 'number_series_lookup_idx');
        });

        Schema::table('employee_code_series', function (Blueprint $table) {
            $table->foreignId('employment_type_id')->nullable()->after('company_id')
                ->constrained('employment_types', indexName: 'employee_code_series_employment_type_fk')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_code_series', function (Blueprint $table) {
            $table->dropForeign('employee_code_series_employment_type_fk');
            $table->dropColumn('employment_type_id');
        });
        Schema::dropIfExists('number_series');
    }
};
