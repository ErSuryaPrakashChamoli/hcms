<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-8c — Rating scales and KPI formulas: a separate competency rating scale per cycle (snapshotted
 * in the pinned template version), KPI rating formulas (achievement band → rating) and reverse KPIs (lower is better).
 * Level ranges (from / to) live in rating_scales.levels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_rating_formulas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('based_on', 32)->default('achievement_over_target')->comment('achievement_over_target|target_over_achievement');
            $table->json('bands')->comment('[{from_pct, to_pct, rating}]');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'kpi_formulas_tenant_code_unique');
        });

        Schema::table('performance_cycles', function (Blueprint $table) {
            $table->foreignId('competency_rating_scale_id')->nullable()->constrained('rating_scales')->nullOnDelete();
        });
        Schema::table('performance_template_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('competency_rating_scale_id')->nullable();
            $table->json('competency_scale_snapshot')->nullable()->comment('null = competencies use the rating scale');
        });
        Schema::table('goals', function (Blueprint $table) {
            $table->boolean('is_reverse')->default(false)->comment('lower is better');
            $table->foreignId('kpi_rating_formula_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('key_results', function (Blueprint $table) {
            $table->boolean('is_reverse')->default(false);
        });
        Schema::table('performance_units', function (Blueprint $table) {
            $table->foreignId('kpi_rating_formula_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('performance_units', fn (Blueprint $table) => $table->dropConstrainedForeignId('kpi_rating_formula_id'));
        Schema::table('key_results', fn (Blueprint $table) => $table->dropColumn('is_reverse'));
        Schema::table('goals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kpi_rating_formula_id');
            $table->dropColumn('is_reverse');
        });
        Schema::table('performance_template_versions', fn (Blueprint $table) => $table->dropColumn(['competency_rating_scale_id', 'competency_scale_snapshot']));
        Schema::table('performance_cycles', fn (Blueprint $table) => $table->dropConstrainedForeignId('competency_rating_scale_id'));
        Schema::dropIfExists('kpi_rating_formulas');
    }
};
