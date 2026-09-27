<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biometric Integration Hub (§24): Device -> Adapter -> Raw punch -> Normalisation -> Engine.
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('adapter', 32)->default('generic');
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->json('settings')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('punched_at');
            $table->string('direction', 8)->default('auto');
            $table->string('source', 16)->default('manual');
            $table->foreignId('attendance_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 128)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'punched_at']);
            $table->unique(['attendance_device_id', 'external_id']);
        });

        // One computed row per employee per day (§23). Reprocessing rewrites it; audit keeps history.
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('not_processed');
            $table->timestamp('first_in')->nullable();
            $table->timestamp('last_out')->nullable();
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_approved_minutes')->default(0);
            $table->boolean('is_regularised')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->json('exceptions')->nullable();
            $table->string('holiday_name')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['tenant_id', 'date', 'status']);
        });

        Schema::create('attendance_regularisations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 32);
            $table->timestamp('requested_in')->nullable();
            $table->timestamp('requested_out')->nullable();
            $table->string('reason');
            $table->string('status', 32)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_regularisations');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_devices');
    }
};
