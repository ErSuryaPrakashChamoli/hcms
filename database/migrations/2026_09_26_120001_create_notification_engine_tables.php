<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->string('subject');
            $table->text('body');
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        // Event -> Rule -> Audience -> Channel -> Template
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('event', 64);
            $table->json('audience');
            $table->json('channels');
            $table->foreignId('notification_template_id')->constrained()->restrictOnDelete();
            $table->json('conditions')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'event', 'status']);
        });

        // Delivery -> Tracking
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32);
            $table->string('event', 64)->nullable();
            $table->string('subject');
            $table->text('body');
            $table->string('status', 32)->default('queued');
            $table->string('recipient')->nullable();
            $table->nullableMorphs('source');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
            $table->index(['tenant_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notification_templates');
    }
};
