<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_lifecycle_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->date('effective_date');
            $table->string('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'effective_date'], 'lifecycle_transitions_employee_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_lifecycle_transitions');
    }
};
