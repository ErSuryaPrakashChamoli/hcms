<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('name');
            $table->string('issuing_body')->nullable();
            $table->string('credential_id')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'person_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_certifications');
    }
};
