<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity BP-2 (CTC formula tags, pay group CTC balancing, "disable CTC"). A salary component may now be
 * calculated as `input` (a per-employee value formulas read, never paid; no column change: calculation_method is a
 * string). A structure version names its balancing component (null = none, as before) and whether it is built from CTC
 * (`ctc`, as before) or from component amounts (`components`: CTC is worked out from them when compensation is proposed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_structure_versions', function (Blueprint $table) {
            $table->foreignId('balancing_component_id')->nullable()->after('parameters')->constrained('salary_components')->restrictOnDelete();
            $table->string('ctc_mode', 16)->default('ctc')->after('balancing_component_id')->comment('ctc|components');
        });
    }

    public function down(): void
    {
        Schema::table('salary_structure_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('balancing_component_id');
            $table->dropColumn('ctc_mode');
        });
    }
};
