<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custom Field Engine (§41). Definitions are tenant configuration; values are typed
        // columns so the common cases stay queryable without touching JSON.
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 32);
            $table->string('key', 64);
            $table->string('label');
            $table->string('type', 32);
            $table->json('options')->nullable();
            $table->string('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('visible_to_employee')->default(true);
            $table->boolean('visible_to_manager')->default(true);
            $table->boolean('visible_to_hr')->default(true);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_reportable')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->json('validation')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'entity', 'key']);
            $table->index(['tenant_id', 'entity', 'status']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 18, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_id', 'model_type', 'model_id'], 'custom_field_values_field_model_unique');
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
