<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity SV-26 (Integration → webhooks):
 * - webhook_endpoints: secret rotation with a grace period — the previous secret (encrypted, never audited or exported)
 *   keeps signing a second header until previous_secret_expires_at, then it is cleared.
 * - webhook_deliveries: how long the last attempt took, and who acknowledged a dead letter (acknowledged dead letters
 *   leave the endpoint's failure count).
 * - webhook_delivery_attempts: one row per HTTP attempt of a delivery (status code, duration, response excerpt), purged
 *   with its delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->text('previous_secret')->nullable()->after('secret');
            $table->timestamp('previous_secret_expires_at')->nullable()->after('previous_secret');
            $table->timestamp('secret_rotated_at')->nullable()->after('previous_secret_expires_at');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('response_excerpt')->comment('duration of the last attempt');
            $table->timestamp('acknowledged_at')->nullable()->after('dead_lettered_at');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('webhook_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_delivery_id')->constrained('webhook_deliveries')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('response_excerpt', 600)->nullable();
            $table->boolean('signed_with_previous')->default(false)->comment('a second signature with the previous secret was sent');
            $table->timestamp('attempted_at');

            $table->index(['webhook_delivery_id', 'attempt'], 'webhook_delivery_attempts_delivery_idx');
            $table->index(['tenant_id', 'attempted_at'], 'webhook_delivery_attempts_tenant_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_attempts');
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropForeign(['acknowledged_by']);
            $table->dropColumn(['duration_ms', 'acknowledged_at', 'acknowledged_by']);
        });
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn(['previous_secret', 'previous_secret_expires_at', 'secret_rotated_at']);
        });
    }
};
