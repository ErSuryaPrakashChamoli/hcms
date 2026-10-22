<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Salary structure versions can be narrowed by any position dimension, not only company and grade.
 | Additive: `applicability` holds {position column: [ids]} for designation, level, department,
 | division and the other EmployeePosition dimensions (null = no narrowing). company_id and grade_ids
 | keep their own columns, so existing versions apply exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_structure_versions', function (Blueprint $table) {
            $table->json('applicability')->nullable()->after('grade_ids');
        });
    }

    public function down(): void
    {
        Schema::table('salary_structure_versions', function (Blueprint $table) {
            $table->dropColumn('applicability');
        });
    }
};
