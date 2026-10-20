<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 8: a course may declare the skill levels its completion evidences ([{skill_id, level}]).
 | The published course version snapshots it, so "skills gained" stays reproducible. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', fn (Blueprint $table) => $table->json('skill_outcomes')->nullable()->after('prerequisite_course_ids'));
        Schema::table('course_versions', fn (Blueprint $table) => $table->json('skill_outcomes')->nullable()->after('assessment'));
    }

    public function down(): void
    {
        Schema::table('course_versions', fn (Blueprint $table) => $table->dropColumn('skill_outcomes'));
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('skill_outcomes'));
    }
};
