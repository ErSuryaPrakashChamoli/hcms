<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Experience transformation (UX): presentation state only, never a system of record for HR data.
 * - experience_preferences: per user theme, density, home layout, pinned people, recent items,
 *   favourite reports, snoozed notifications.
 * - ux_metrics: anonymous, aggregate product counters per tenant and day (no user, no content).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('preferences')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id'], 'experience_preferences_user_unique');
        });

        Schema::create('ux_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('metric', 64);
            $table->unsignedBigInteger('count')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);

            $table->unique(['tenant_id', 'day', 'metric'], 'ux_metrics_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ux_metrics');
        Schema::dropIfExists('experience_preferences');
    }
};
