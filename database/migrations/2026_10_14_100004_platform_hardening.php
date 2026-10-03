<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 platform hardening (additive):
 * - scheduler_claims: once-per-period slots per tenant for scheduled sweeps without their own log.
 * - notification_deliveries: correlation id (request / command run) and a dedupe key, unique per
 *   tenant, so a retried job or a repeated dispatch in the same operation never notifies twice.
 * - grievance_notes: SHA-256 fingerprint of evidence, verified on download.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('period', 32);
            $table->timestamp('claimed_at');

            $table->unique(['tenant_id', 'key', 'period'], 'scheduler_claims_unique');
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->string('correlation_id', 64)->nullable()->after('error');
            $table->string('dedupe_key', 64)->nullable()->after('correlation_id');
            $table->unique(['tenant_id', 'dedupe_key'], 'notification_deliveries_dedupe_unique');
        });

        Schema::table('grievance_notes', function (Blueprint $table) {
            $table->string('attachment_sha256', 64)->nullable()->after('attachment_name');
        });
    }

    public function down(): void
    {
        Schema::table('grievance_notes', function (Blueprint $table) {
            $table->dropColumn('attachment_sha256');
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropUnique('notification_deliveries_dedupe_unique');
            $table->dropColumn(['correlation_id', 'dedupe_key']);
        });

        Schema::dropIfExists('scheduler_claims');
    }
};
