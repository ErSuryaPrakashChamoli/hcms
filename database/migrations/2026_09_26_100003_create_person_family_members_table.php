<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('name');
            $table->string('relation', 32);
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 32)->nullable();
            $table->boolean('is_dependent')->default(false);
            $table->boolean('is_nominee')->default(false);
            $table->decimal('nominee_share', 5, 2)->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_family_members');
    }
};
