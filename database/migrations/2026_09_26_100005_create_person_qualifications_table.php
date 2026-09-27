<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('qualification');
            $table->string('specialisation')->nullable();
            $table->string('institution')->nullable();
            $table->string('board_or_university')->nullable();
            $table->unsignedSmallInteger('year_of_completion')->nullable();
            $table->string('grade_or_score', 32)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_qualifications');
    }
};
