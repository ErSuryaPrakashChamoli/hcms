<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('employer');
            $table->string('designation')->nullable();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->string('location')->nullable();
            $table->text('responsibilities')->nullable();
            $table->string('reason_for_leaving')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'person_id', 'from_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_experiences');
    }
};
