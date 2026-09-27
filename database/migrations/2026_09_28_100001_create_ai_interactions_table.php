<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('assistant', 32);
            $table->text('question');
            $table->longText('answer');
            $table->json('sources')->nullable();
            $table->json('actions')->nullable();
            $table->string('intent', 64)->nullable();
            $table->string('provider', 32)->default('deterministic');
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('feedback', 8)->nullable();
            $table->text('feedback_note')->nullable();
            $table->boolean('is_inference')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'assistant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
