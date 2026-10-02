<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12: `tickets` becomes the one canonical HR service request / case. It gains:
 * - the pinned service version and form data (encrypted, purged after a domain change);
 * - the controlled lifecycle (status map);
 * - team / agent / owner assignment;
 * - confidentiality;
 * - a business-hours SLA with pause;
 * - escalation level;
 * - the domain-action hand-off;
 * - idempotency, correlation and optimistic-lock columns.
 *
 * Comments gain a visibility (employee / internal / restricted). The supporting tables are:
 * - ticket_transitions (status history shown on the case);
 * - ticket_access_grants (explicit scope for restricted cases);
 * - service_desk_reminder_logs (idempotent reminders and escalations).
 *
 * Existing statuses are renamed to the Phase 12 lifecycle, and rolled back on down():
 * - new → submitted;
 * - open → in_progress;
 * - pending → waiting_employee.
 */
return new class extends Migration
{
    private const STATUS_RENAMES = ['new' => 'submitted', 'open' => 'in_progress', 'pending' => 'waiting_employee'];

    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('service_definition_id')->nullable()->after('ticket_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_definition_version_id')->nullable()->after('service_definition_id')->constrained()->restrictOnDelete();
            $table->string('source', 16)->default('web')->after('raised_by');
            $table->longText('form_data')->nullable()->after('description');
            $table->timestamp('form_data_purged_at')->nullable()->after('form_data');
            $table->string('confidentiality', 16)->default('standard')->after('priority');
            $table->boolean('visible_to_employee')->default(true)->after('confidentiality');
            $table->foreignId('assigned_role_id')->nullable()->after('assignee_id')->constrained('roles')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->after('assigned_role_id')->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->foreignId('sla_policy_id')->nullable()->constrained('service_sla_policies')->nullOnDelete();
            $table->string('sla_mode', 16)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('sla_paused_at')->nullable();
            $table->unsignedInteger('sla_paused_minutes')->default(0);
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('domain_action', 64)->nullable();
            $table->string('domain_action_status', 24)->nullable();
            $table->string('domain_reference_type', 64)->nullable();
            $table->unsignedBigInteger('domain_reference_id')->nullable();
            $table->timestamp('domain_action_executed_at')->nullable();
            $table->foreignId('domain_action_executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('correlation_id', 26)->nullable();
            $table->string('operation_id', 26)->nullable();
            $table->unsignedInteger('lock_version')->default(0);

            $table->unique(['tenant_id', 'employee_id', 'idempotency_key'], 'tickets_idempotency_unique');
            $table->index(['tenant_id', 'status', 'due_at'], 'tickets_sla_index');
            $table->index(['tenant_id', 'assigned_role_id', 'status'], 'tickets_team_index');
            $table->index(['tenant_id', 'service_definition_id', 'status'], 'tickets_service_index');
            $table->index(['tenant_id', 'priority', 'status'], 'tickets_priority_index');
            $table->index(['tenant_id', 'created_at'], 'tickets_created_index');
            $table->index(['tenant_id', 'resolved_at'], 'tickets_resolved_index');
            $table->index(['tenant_id', 'raised_by'], 'tickets_requester_index');
            $table->index('correlation_id');
        });

        foreach (self::STATUS_RENAMES as $from => $to) {
            DB::table('tickets')->where('status', $from)->update(['status' => $to]);
        }
        DB::table('tickets')->whereNull('submitted_at')->update(['submitted_at' => DB::raw('created_at'), 'status_changed_at' => DB::raw('updated_at')]);

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->string('visibility', 16)->default('employee')->after('is_internal');
            $table->unsignedInteger('attachment_size')->nullable()->after('attachment_name');
            $table->string('attachment_mime', 128)->nullable()->after('attachment_size');
            $table->string('attachment_sha256', 64)->nullable()->after('attachment_mime');
        });
        DB::table('ticket_comments')->where('is_internal', true)->update(['visibility' => 'internal']);

        Schema::create('ticket_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('via', 16)->default('user');
            $table->text('reason')->nullable();
            $table->string('operation_id', 26)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('ticket_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ticket_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('service_desk_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reminder', 32);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('bucket', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'bucket'], 'service_desk_reminder_logs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_desk_reminder_logs');
        Schema::dropIfExists('ticket_access_grants');
        Schema::dropIfExists('ticket_transitions');

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropColumn(['visibility', 'attachment_size', 'attachment_mime', 'attachment_sha256']);
        });

        foreach (['submitted' => 'new', 'acknowledged' => 'new', 'draft' => 'new', 'assigned' => 'open', 'in_progress' => 'open', 'waiting_hr' => 'open', 'awaiting_approval' => 'open', 'waiting_employee' => 'pending', 'cancelled' => 'closed'] as $from => $to) {
            DB::table('tickets')->where('status', $from)->update(['status' => $to]);
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique('tickets_idempotency_unique');
            foreach (['tickets_sla_index', 'tickets_team_index', 'tickets_service_index', 'tickets_priority_index', 'tickets_created_index', 'tickets_resolved_index', 'tickets_requester_index'] as $index) {
                $table->dropIndex($index);
            }
            $table->dropIndex(['correlation_id']);
            foreach (['service_definition_id', 'service_definition_version_id', 'assigned_role_id', 'owner_id', 'resolved_by', 'sla_policy_id', 'domain_action_executed_by', 'approved_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn([
                'source', 'form_data', 'form_data_purged_at', 'confidentiality', 'visible_to_employee', 'sla_mode', 'submitted_at', 'acknowledged_at', 'assigned_at',
                'status_changed_at', 'sla_paused_at', 'sla_paused_minutes', 'escalation_level', 'cancelled_at', 'domain_action', 'domain_action_status',
                'domain_reference_type', 'domain_reference_id', 'domain_action_executed_at', 'approved_at', 'idempotency_key', 'correlation_id', 'operation_id', 'lock_version',
            ]);
        });
    }
};
