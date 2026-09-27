<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. No updated_at, no soft deletes: the model refuses updates and deletes,
        // and each event carries a hash chained to the previous event of the same tenant.
        Schema::create('audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->json('actor_roles')->nullable();
            $table->string('action', 64);
            $table->string('module', 64);
            $table->string('entity_type')->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('entity_label')->nullable();
            $table->timestamp('occurred_at', 6);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('source', 64);
            $table->string('request_id', 64)->nullable();
            $table->text('reason')->nullable();
            $table->string('approval_reference')->nullable();
            $table->date('effective_date')->nullable();
            $table->json('metadata')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['tenant_id', 'action']);
            $table->index('request_id');
        });

        Schema::create('audit_event_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('audit_event_id')->constrained('audit_events')->restrictOnDelete();
            $table->string('field', 128);
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->boolean('is_sensitive')->default(false);

            $table->index('audit_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_event_changes');
        Schema::dropIfExists('audit_events');
    }
};
