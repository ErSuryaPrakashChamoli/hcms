<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity GO-15 (Engage → Events, "Add event"): company events with their sessions, invitations and attendance.
 *
 * - `company_events`: what the event is (title, type / sub-type from the option lists, description, agenda), who hosts it,
 *   whether attending is mandatory, seats (null = unlimited), how it is held (online / offline / hybrid with a location or
 *   a meeting URL), its time zone, material (attachments on the documents disk, a video link), the audience (a saved
 *   audience or criteria, resolved in the preparer's scope when it is published), its status (draft → published →
 *   completed, or cancelled with a reason) and the optional pre- / post-event feedback templates. `starts_at` / `ends_at`
 *   repeat the first session's start and the last session's end for the register's timeline filter.
 * - `company_event_sessions`: one row per occurrence (a recurring event has several, generated at creation).
 * - `company_event_invitations`: one per event and employee — invited (audience or HR), nominated (by a colleague),
 *   accepted or declined, with when they answered and who nominated them.
 * - `company_event_attendances`: one per session and employee — attended or not, who marked it and when.
 *
 * Nothing is deleted when an event is cancelled: the rows stay as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('event_type', 48)->nullable();
            $table->string('event_sub_type', 48)->nullable();
            $table->text('description')->nullable();
            $table->text('agenda')->nullable();
            $table->foreignId('host_employee_id')->nullable()->constrained('employees', indexName: 'company_events_host_fk')->nullOnDelete();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('allow_nomination')->default(false);
            $table->unsignedInteger('seats')->nullable();
            $table->string('mode', 16)->default('offline');
            $table->foreignId('location_id')->nullable()->constrained('locations', indexName: 'company_events_location_fk')->nullOnDelete();
            $table->string('venue', 255)->nullable();
            $table->string('meeting_url', 500)->nullable();
            $table->string('timezone', 64);
            $table->json('recurrence')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('attachments')->nullable();
            $table->string('video_url', 500)->nullable();
            $table->foreignId('audience_id')->nullable()->constrained('audiences', indexName: 'company_events_audience_fk')->nullOnDelete();
            $table->json('audience_criteria')->nullable();
            $table->foreignId('pre_feedback_template_id')->nullable()->constrained('feedback_templates', indexName: 'company_events_pre_feedback_fk')->nullOnDelete();
            $table->foreignId('post_feedback_template_id')->nullable()->constrained('feedback_templates', indexName: 'company_events_post_feedback_fk')->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('invited_count')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'company_events_created_by_fk')->nullOnDelete();
            $table->foreignId('scope_user_id')->nullable()->constrained('users', indexName: 'company_events_scope_user_fk')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users', indexName: 'company_events_published_by_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users', indexName: 'company_events_cancelled_by_fk')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'starts_at'], 'company_events_register_index');
        });

        Schema::create('company_event_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('company_events', indexName: 'company_event_sessions_event_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->timestamp('starts_at');
            $table->unsignedInteger('duration_minutes');
            $table->timestamps();

            $table->unique(['event_id', 'sequence'], 'company_event_sessions_sequence_unique');
            $table->index(['tenant_id', 'starts_at'], 'company_event_sessions_start_index');
        });

        Schema::create('company_event_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('company_events', indexName: 'company_event_invitations_event_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees', indexName: 'company_event_invitations_employee_fk')->cascadeOnDelete();
            $table->string('status', 16)->default('invited');
            $table->string('source', 16)->default('audience');
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('nominated_by')->nullable()->constrained('employees', indexName: 'company_event_invitations_nominated_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'employee_id'], 'company_event_invitations_once_unique');
            $table->index(['tenant_id', 'employee_id', 'status'], 'company_event_invitations_employee_index');
        });

        Schema::create('company_event_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('company_event_sessions', indexName: 'company_event_attendances_session_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees', indexName: 'company_event_attendances_employee_fk')->cascadeOnDelete();
            $table->boolean('attended');
            $table->foreignId('marked_by')->nullable()->constrained('users', indexName: 'company_event_attendances_marked_by_fk')->nullOnDelete();
            $table->timestamp('marked_at');
            $table->timestamps();

            $table->unique(['session_id', 'employee_id'], 'company_event_attendances_once_unique');
            $table->index(['tenant_id', 'employee_id'], 'company_event_attendances_employee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_event_attendances');
        Schema::dropIfExists('company_event_invitations');
        Schema::dropIfExists('company_event_sessions');
        Schema::dropIfExists('company_events');
    }
};
