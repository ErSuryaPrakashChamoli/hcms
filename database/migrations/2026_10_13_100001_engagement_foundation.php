<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13: engagement surveys, feedback and campaigns.
 *
 * Anonymity is a schema property here, not a UI convention:
 * - survey_participations (who was eligible / took part, dates only) and survey_responses /
 *   survey_answers (what was answered) share no key;
 * - responses and answers have random UUID keys and no timestamps;
 * - confidential identity lives only in engagement_identities.
 *
 * See docs/architecture/engagement-communication.md §3. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('criteria');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('category', 32)->default('engagement');
            $table->string('survey_type', 32)->default('engagement');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('survey_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->text('intro')->nullable();
            $table->string('anonymity_mode', 16)->default('anonymous');
            $table->string('response_rule', 16)->default('once');
            $table->string('response_period', 16)->nullable();
            $table->foreignId('audience_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('audience_criteria')->nullable();
            $table->string('breakdown_dimension', 32)->nullable();
            $table->json('result_visibility')->nullable();
            $table->json('reminder_policy')->nullable();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scope_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('workflow_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('eligible_count')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('operation_id', 26)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['survey_id', 'version']);
            $table->index(['tenant_id', 'status', 'opens_at'], 'survey_versions_open_index');
            $table->index(['tenant_id', 'status', 'closes_at'], 'survey_versions_close_index');
        });

        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_version_id')->constrained()->restrictOnDelete();
            $table->string('key', 64);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('type', 24);
            $table->text('prompt');
            $table->text('help')->nullable();
            $table->boolean('required')->default(false);
            $table->json('options')->nullable();
            $table->json('scale')->nullable();
            $table->json('admin_metadata')->nullable();
            $table->json('scoring')->nullable();
            $table->json('analysis_tags')->nullable();
            $table->timestamps();

            $table->unique(['survey_version_id', 'key']);
        });

        // Identity side: no time of day, no updated_at (§3).
        Schema::create('survey_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('group_key', 64)->nullable();
            $table->string('status', 16)->default('invited');
            $table->date('invited_on')->nullable();
            $table->date('opened_on')->nullable();
            $table->date('submitted_on')->nullable();
            $table->date('expired_on')->nullable();
            $table->unsignedTinyInteger('reminders_sent')->default(0);

            $table->unique(['survey_version_id', 'employee_id']);
            $table->index(['tenant_id', 'survey_version_id', 'status'], 'survey_participations_status_index');
        });

        // Content side: random UUID keys, no timestamps, no employee for confidential / anonymous (§3).
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_key', 64)->nullable();
            $table->string('period_key', 16)->nullable();
            $table->string('status', 16)->default('submitted');
            $table->unsignedTinyInteger('active_key')->nullable()->default(1);
            $table->uuid('supersedes_id')->nullable();
            $table->date('submitted_on')->nullable();
            $table->string('idempotency_key', 64)->nullable();

            $table->unique(['survey_version_id', 'employee_id', 'period_key', 'active_key'], 'survey_responses_one_per_period');
            $table->unique(['survey_version_id', 'employee_id', 'idempotency_key'], 'survey_responses_idempotency');
            $table->index(['tenant_id', 'survey_version_id', 'status', 'group_key'], 'survey_responses_results_index');
        });

        Schema::create('survey_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_version_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('response_id')->constrained('survey_responses')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('survey_questions')->restrictOnDelete();
            $table->string('value_option', 64)->nullable();
            $table->decimal('value_number', 12, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->text('value_text')->nullable();

            $table->index(['survey_version_id', 'question_id', 'value_option'], 'survey_answers_results_index');
        });

        // Confidential mode only: the restricted link from a response / feedback item to its author.
        Schema::create('engagement_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 64);
            $table->string('subject_id', 36);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->unique(['subject_type', 'subject_id']);
        });

        Schema::create('employee_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 16);
            $table->string('category', 32);
            $table->text('body');
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('new');
            $table->date('submitted_on');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('handling_note')->nullable();
            $table->string('referred_type', 64)->nullable();
            $table->unsignedBigInteger('referred_id')->nullable();
            $table->date('closed_on')->nullable();

            $table->index(['tenant_id', 'status', 'category'], 'employee_feedback_inbox_index');
        });

        Schema::create('engagement_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('purpose')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('audience_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('workflow_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('operation_id', 26)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'idempotency_key'], 'engagement_campaigns_idempotency');
            $table->index(['tenant_id', 'status', 'starts_on'], 'engagement_campaigns_start_index');
        });

        Schema::create('campaign_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('engagement_campaigns')->cascadeOnDelete();
            $table->string('item_type', 32);
            $table->unsignedBigInteger('item_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'item_type', 'item_id']);
        });

        Schema::create('engagement_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reminder', 32);
            $table->string('subject_type', 64);
            $table->string('subject_id', 36);
            $table->string('bucket', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'bucket'], 'engagement_reminder_logs_unique');
        });
    }

    public function down(): void
    {
        foreach (['engagement_reminder_logs', 'campaign_items', 'engagement_campaigns', 'employee_feedback', 'engagement_identities', 'survey_answers', 'survey_responses', 'survey_participations', 'survey_questions', 'survey_versions', 'surveys', 'audiences'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
