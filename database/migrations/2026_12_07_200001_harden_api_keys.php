<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity CA-11 / SV-26 (Integration → API key management):
 * - api_keys: an optional description, a per-key IP allowlist (IPs / CIDR blocks; null or empty = any address, as
 *   before), a per-key rate limit (null = the API-wide limit, as before) and the key a regenerated key replaced.
 * - api_request_logs: one row per API call made with a verified key — method, path, route name, status, duration, IP and
 *   user agent. Never a request or response body, a header or a query string. Purged by retention.api_request_logs_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('name');
            $table->json('allowed_ips')->nullable()->after('scopes')->comment('IPs or CIDR blocks; null / empty = any address');
            $table->unsignedInteger('rate_limit_per_minute')->nullable()->after('allowed_ips')->comment('null = peopleos.api.rate_limit_per_minute');
            $table->foreignId('regenerated_from_id')->nullable()->after('created_by')->constrained('api_keys')->nullOnDelete();
        });

        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('path', 255)->comment('request path with ids, never the query string');
            $table->string('route_name', 128)->nullable();
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'api_key_id', 'created_at'], 'api_request_logs_key_created_idx');
            $table->index(['tenant_id', 'created_at'], 'api_request_logs_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropForeign(['regenerated_from_id']);
            $table->dropColumn(['description', 'allowed_ips', 'rate_limit_per_minute', 'regenerated_from_id']);
        });
    }
};
