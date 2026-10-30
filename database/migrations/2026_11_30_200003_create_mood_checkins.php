<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-9f (Engage → Happiness index, the mood bot). Built on the engagement anonymity architecture
 * (docs/architecture/engagement-communication.md §3):
 *
 * - `mood_reasons`: the reason tags employees pick (at most 20 active; HROne's 14 seeded).
 * - `mood_participations` (identity side): who checked in for which period, a date only, no timestamps. It is what
 *   keeps one check-in per person and period.
 * - `mood_checkins` (content side): random v4 UUID keys, no timestamps, the period and the group key (the employee's
 *   department merged to groups of at least the privacy threshold), the mood, reasons and comment. An employee and a date
 *   only when the organisation chose identified check-ins; confidential authorship goes only to engagement_identities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mood_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('status', 16)->default('active');
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('mood_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 16);
            $table->date('checked_in_on');

            $table->unique(['tenant_id', 'employee_id', 'period_key'], 'mood_participations_once_unique');
        });

        Schema::create('mood_checkins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 16);
            $table->string('group_key', 64)->nullable();
            $table->unsignedTinyInteger('mood');
            $table->json('reason_ids')->nullable();
            $table->text('comment')->nullable();
            $table->string('privacy', 16);
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->date('checked_in_on')->nullable();

            $table->index(['tenant_id', 'period_key', 'group_key'], 'mood_checkins_results_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mood_checkins');
        Schema::dropIfExists('mood_participations');
        Schema::dropIfExists('mood_reasons');
    }
};
