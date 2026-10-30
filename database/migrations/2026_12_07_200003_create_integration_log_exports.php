<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity SV-26: queued CSV exports of the webhook delivery log and of an API key's call log. The file is kept on
 * the documents disk under tenants/{id}/integrations and is downloaded only by the person who asked for it (audited);
 * rows and files are purged with retention.report_runs_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_log_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32)->comment('webhook_deliveries|api_request_logs');
            $table->json('filters')->nullable();
            $table->string('status', 16)->default('queued')->comment('queued|running|completed|failed');
            $table->unsignedInteger('row_count')->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'requested_by', 'created_at'], 'integration_log_exports_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_log_exports');
    }
};
