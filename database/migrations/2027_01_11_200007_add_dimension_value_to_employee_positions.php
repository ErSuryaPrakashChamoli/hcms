<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-1 (decision D1): an employee's position may name a value of the organisation's own dimension, as an
 * optional organisation field like department or cost centre. Nullable: every position on file keeps no value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_positions', function (Blueprint $table) {
            $table->foreignId('dimension_value_id')->nullable()->after('cost_centre_id')->constrained('dimension_values', indexName: 'employee_positions_dimension_value_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_positions', function (Blueprint $table) {
            $table->dropForeign('employee_positions_dimension_value_fk');
            $table->dropColumn('dimension_value_id');
        });
    }
};
