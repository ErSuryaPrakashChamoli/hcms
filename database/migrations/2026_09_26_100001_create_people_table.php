<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The lifetime person record (blueprint §4). Employment stints hang off it.
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('preferred_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('marital_status', 32)->nullable();
            $table->string('nationality', 64)->nullable();
            $table->string('blood_group', 8)->nullable();
            $table->string('personal_email')->nullable();
            $table->string('personal_phone', 32)->nullable();
            $table->string('photo_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'last_name', 'first_name']);
            $table->index(['tenant_id', 'personal_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
