<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-8g — Calibration aids. Bell curve: the target share of employees per rating level, for one
 * cycle or (no cycle) as the organisation's default, compared with the actual distribution on the calibration board.
 * 9-box: the low / moderate / high bands of the performance and potential axes, for one cycle or as the default (none =
 * thirds of each axis's scale). Both are analysis aids, editable after a cycle is launched (not part of its pinned
 * template version).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_distribution_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_cycle_id')->nullable()->comment('null = the default for every cycle')
                ->constrained('performance_cycles', 'id', 'perf_dist_targets_cycle_fk')->cascadeOnDelete();
            $table->decimal('level_value', 6, 2)->comment('a level value of the rating scale');
            $table->decimal('target_pct', 5, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'performance_cycle_id', 'level_value'], 'perf_dist_targets_level_unique');
        });

        Schema::create('performance_nine_box_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performance_cycle_id')->nullable()->comment('null = the default for every cycle')
                ->constrained('performance_cycles', 'id', 'perf_nine_box_cycle_fk')->cascadeOnDelete();
            $table->string('axis', 16)->comment('performance|potential');
            $table->string('band', 16)->comment('low|moderate|high');
            $table->decimal('from_value', 6, 2)->comment('a value at or above this falls in the band (below the next band)');
            $table->decimal('to_value', 6, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'performance_cycle_id', 'axis', 'band'], 'perf_nine_box_bands_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_nine_box_bands');
        Schema::dropIfExists('performance_distribution_targets');
    }
};
