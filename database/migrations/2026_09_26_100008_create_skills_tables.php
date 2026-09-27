<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->string('category', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('person_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('proficiency', 32)->nullable();
            $table->unsignedSmallInteger('years_of_experience')->nullable();
            $table->date('last_used_on')->nullable();
            $table->timestamps();

            $table->unique(['person_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_skills');
        Schema::dropIfExists('skills');
    }
};
