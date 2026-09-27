<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The People Timeline (blueprint §18): one row per material event, written by services.
        Schema::create('employee_timeline_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_on');
            $table->string('category', 32);
            $table->string('title');
            $table->string('description', 1000)->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'occurred_on'], 'timeline_entries_employee_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_timeline_entries');
    }
};
