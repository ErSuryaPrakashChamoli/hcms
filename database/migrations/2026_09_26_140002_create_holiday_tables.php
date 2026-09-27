<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('holiday_calendar_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->string('type', 32)->default('public');
            $table->boolean('is_half_day')->default(false);
            $table->timestamps();

            $table->unique(['holiday_calendar_id', 'date']);
        });

        // Which calendar applies (§15): by company, location, department... expressed as rules.
        Schema::create('holiday_calendar_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('holiday_calendar_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('priority')->default(100);
            $table->json('conditions');
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_calendar_rules');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('holiday_calendars');
    }
};
