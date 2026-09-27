<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('name');
            $table->string('relation', 64)->nullable();
            $table->string('phone', 32);
            $table->string('alternate_phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->unsignedTinyInteger('priority')->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'person_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_emergency_contacts');
    }
};
