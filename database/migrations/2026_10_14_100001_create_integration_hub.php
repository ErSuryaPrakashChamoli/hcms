<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 Integration Hub (ADR-0011, ADR-0012, ADR-0014), additive only.
 *
 * - integration_systems: an external system registered by a tenant, with its own inbound signing
 *   secret and optionally the one API key allowed to post for it.
 * - external_references: PeopleOS entity ↔ external system reference. PeopleOS ids stay canonical;
 *   an external id is never a primary key.
 * - inbound_events: received → processing → succeeded | retrying → dead_letter, or failed.
 *   - Unique per (tenant, system, idempotency key).
 *   - The payload is encrypted and purged after processing plus a retention window; metadata
 *     (keys, size, checksum) stays.
 * - integration_mappings: an external value (company, department, …) → PeopleOS record.
 * - webhook_deliveries (the existing outbox) gains a correlation id, a dead-letter state with replay,
 *   and one row per endpoint and event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('kind', 32)->default('other');
            $table->string('status', 16)->default('active');
            $table->text('inbound_secret')->nullable();
            $table->boolean('require_signature')->default(true);
            $table->unsignedSmallInteger('signature_tolerance_seconds')->default(300);
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->json('allowed_event_types')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('external_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_system_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');
            $table->string('external_entity_type', 64);
            $table->string('external_entity_id', 191);
            $table->string('external_reference', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'integration_system_id', 'external_entity_type', 'external_entity_id'], 'external_references_external_unique');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'external_references_entity_index');
        });

        Schema::create('inbound_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_system_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('external_event_id', 191);
            $table->string('idempotency_key', 191);
            $table->string('correlation_id', 64);
            $table->string('status', 16)->default('received');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('reprocess_count')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->longText('payload')->nullable();
            $table->string('payload_sha256', 64);
            $table->unsignedInteger('payload_size')->default(0);
            $table->json('payload_metadata')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('payload_purged_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'integration_system_id', 'idempotency_key'], 'inbound_events_idempotency_unique');
            $table->index(['tenant_id', 'status', 'next_attempt_at'], 'inbound_events_due_index');
            $table->index(['tenant_id', 'correlation_id'], 'inbound_events_correlation_index');
        });

        Schema::create('integration_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_system_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 32);
            $table->string('external_value', 191);
            $table->string('peopleos_type', 64);
            $table->unsignedBigInteger('peopleos_id');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'integration_system_id', 'dimension', 'external_value'], 'integration_mappings_unique');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->string('correlation_id', 64)->nullable()->after('event_id');
            $table->timestamp('dead_lettered_at')->nullable()->after('delivered_at');
            $table->unsignedTinyInteger('replay_count')->default(0)->after('attempts');
            $table->unique(['webhook_endpoint_id', 'event_id'], 'webhook_deliveries_endpoint_event_unique');
        });
    }

    public function down(): void
    {
        // Rows that reached the dead-letter state go back to the pre-Phase 14 terminal status.
        DB::table('webhook_deliveries')->where('status', 'dead_letter')->update(['status' => 'failed']);
        // MySQL may have dropped the foreign key's implicit index in favour of the unique key (which
        // starts with the same column): release the foreign key, drop the unique key, then restore it.
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropForeign(['webhook_endpoint_id']);
        });
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropUnique('webhook_deliveries_endpoint_event_unique');
            $table->dropColumn(['correlation_id', 'dead_lettered_at', 'replay_count']);
        });
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->foreign('webhook_endpoint_id')->references('id')->on('webhook_endpoints')->cascadeOnDelete();
        });
        Schema::dropIfExists('integration_mappings');
        Schema::dropIfExists('inbound_events');
        Schema::dropIfExists('external_references');
        Schema::dropIfExists('integration_systems');
    }
};
