<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shift engine (§13, §14). Timing rules are data; nothing here is hardcoded per client.
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('type', 16)->default('fixed');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('full_day_minutes')->default(480);
            $table->unsignedSmallInteger('half_day_minutes')->default(240);
            $table->unsignedSmallInteger('grace_in_minutes')->default(0);
            $table->unsignedSmallInteger('grace_out_minutes')->default(0);
            $table->boolean('overtime_eligible')->default(false);
            $table->unsignedSmallInteger('min_overtime_minutes')->default(30);
            $table->string('status', 32)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // pattern = [ week => [ mon => shift_id|null, ... ] ]; more than one week means rotation.
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->json('pattern');
            $table->string('status', 32)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('work_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'effective_from'], 'schedule_assignments_employee_from_idx');
        });

        // Default schedule per group of employees (rules), so individual assignment is the exception.
        Schema::create('work_schedule_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('priority')->default(100);
            $table->json('conditions');
            $table->string('status', 32)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_rules');
        Schema::dropIfExists('work_schedule_assignments');
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('shifts');
    }
};
