<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 1 §12: concurrency-safe, predictable employee codes — one locked counter per tenant and prefix. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_code_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 16);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_code_sequences');
    }
};
