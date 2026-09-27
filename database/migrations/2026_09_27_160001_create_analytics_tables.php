<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('dataset', 64);
            $table->text('description')->nullable();
            $table->json('definition');
            $table->boolean('is_shared')->default(false);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'dataset']);
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 16)->default('weekly');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('time', 5)->default('07:00');
            $table->json('recipient_user_ids');
            $table->string('format', 8)->default('csv');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('format', 8)->default('csv');
            $table->unsignedInteger('row_count')->default(0);
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->string('status', 32)->default('completed');
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'started_at']);
        });

        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 64);
            $table->text('description')->nullable();
            $table->json('role_ids')->nullable();
            $table->json('widgets');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        foreach (['dashboards', 'report_runs', 'report_schedules', 'reports'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
