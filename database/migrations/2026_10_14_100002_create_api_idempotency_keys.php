<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14: the generic `Idempotency-Key` store for v1 write endpoints (ADR-0014).
 *
 * One row per (tenant, API key, key):
 * - the first request records "processing" and then its response (encrypted);
 * - a repeat with the same body replays that response;
 * - a repeat with another body is refused;
 * - a concurrent duplicate gets a 409 while the first is in flight.
 *
 * Rows expire after 24 hours (purged by retention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 191);
            $table->string('method', 8);
            $table->string('path', 255);
            $table->string('request_sha256', 64);
            $table->string('status', 16)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'api_key_id', 'idempotency_key'], 'api_idempotency_keys_unique');
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
