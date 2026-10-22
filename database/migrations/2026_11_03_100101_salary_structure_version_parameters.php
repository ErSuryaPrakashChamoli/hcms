<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure parameters: named numbers on a structure version (basic_pct = 50) that its formulas use,
 * so one formula serves structures with different percentages and limits. Versioned and approved with
 * the version like the rest of its content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_structure_versions', function (Blueprint $table) {
            $table->json('parameters')->nullable()->after('grade_ids');
        });
    }

    public function down(): void
    {
        Schema::table('salary_structure_versions', function (Blueprint $table) {
            $table->dropColumn('parameters');
        });
    }
};
