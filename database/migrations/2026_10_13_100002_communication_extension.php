<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13: the existing communication centre (announcements) gains:
 * - review and approval;
 * - frozen content and versions (a correction supersedes);
 * - priority, campaign and a structured, snapshotted audience;
 * - one private attachment;
 * - delivery tracking per recipient (communication_recipients, delivered through the existing
 *   Notifier);
 * - employee preferences for optional communication.
 *
 * Additive only; existing statuses (draft / published / archived) remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('priority', 16)->default('normal')->after('type');
            $table->unsignedInteger('version')->default(1)->after('priority');
            $table->foreignId('supersedes_id')->nullable()->after('version')->constrained('announcements')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->after('article_id')->constrained('engagement_campaigns')->nullOnDelete();
            $table->foreignId('audience_id')->nullable()->after('audience')->constrained()->restrictOnDelete();
            $table->json('audience_criteria')->nullable()->after('audience_id');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scope_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('workflow_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_sha256', 64)->nullable();
            $table->unsignedInteger('recipients_count')->nullable();
            $table->string('operation_id', 26)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->unsignedInteger('lock_version')->default(0);

            $table->unique(['tenant_id', 'idempotency_key'], 'announcements_idempotency');
        });

        Schema::table('announcement_reads', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('acknowledged_at');
        });

        Schema::create('communication_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->json('channels')->nullable();
            $table->string('skipped_reason', 32)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'employee_id']);
            $table->index(['tenant_id', 'announcement_id', 'status'], 'communication_recipients_status_index');
        });

        Schema::create('communication_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);
            $table->timestamps();

            $table->unique(['employee_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_preferences');
        Schema::dropIfExists('communication_recipients');
        Schema::table('announcement_reads', function (Blueprint $table) {
            $table->dropColumn('source');
        });
        // Statuses the pre-Phase 13 code knows: draft, published, archived.
        DB::table('announcements')->whereIn('status', ['in_review', 'approved', 'scheduled'])->update(['status' => 'draft']);
        DB::table('announcements')->where('status', 'cancelled')->update(['status' => 'archived']);
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropUnique('announcements_idempotency');
            foreach (['supersedes_id', 'campaign_id', 'audience_id', 'prepared_by', 'scope_user_id', 'approved_by', 'workflow_instance_id'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['priority', 'version', 'audience_criteria', 'submitted_at', 'approved_at', 'decision_note', 'published_at', 'cancelled_at', 'attachment_path', 'attachment_name', 'attachment_sha256', 'recipients_count', 'operation_id', 'idempotency_key', 'lock_version']);
        });
    }
};
