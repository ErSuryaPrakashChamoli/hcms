<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-8h (SV-12, PF-1 … PF-5) — 360 feedback rounds under a 360 policy (type `feedback_360`):
 *
 * - feedback_rounds: one round of a policy for a period (its frequency), with a response window and a snapshot of the
 *   policy's answers and of each source's feedback template (P13) at launch;
 * - feedback_participants: an assessed employee in a round and the state of their nomination set (the approval unit);
 * - feedback_nominations: who is asked (identity side): the manager, the functional manager, nominated peers and
 *   subordinates, or an external client (name and e-mail only, reached through a signed, expiring link);
 * - feedback_responses: what was answered (content side): a random key, no timestamps, and a link to the nomination only
 *   when its source is not anonymous, so an anonymous answer cannot be traced back to the person who gave it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->comment('the 360 policy (type feedback_360) the round runs')
                ->constrained('policies', 'id', 'feedback_rounds_policy_fk')->restrictOnDelete();
            $table->foreignId('performance_cycle_id')->nullable()->comment('appraisal cycle the 360 rating may blend into')
                ->constrained('performance_cycles', 'id', 'feedback_rounds_cycle_fk')->nullOnDelete();
            $table->string('name', 160);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('window_opens_on');
            $table->date('window_closes_on');
            $table->string('status', 16)->default('open')->comment('open|closed|cancelled');
            $table->boolean('auto')->default(false)->comment('launched by the schedule');
            $table->json('settings')->nullable()->comment('the policy answers at launch');
            $table->json('templates')->nullable()->comment('per source: template id, name and a copy of its questions');
            $table->unsignedSmallInteger('extensions')->default(0);
            $table->foreignId('launched_by')->nullable()->constrained('users', 'id', 'feedback_rounds_launched_by_fk')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'policy_id', 'period_start'], 'feedback_rounds_policy_period_index');
            $table->index(['tenant_id', 'status'], 'feedback_rounds_status_index');
        });

        Schema::create('feedback_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feedback_round_id')->constrained('feedback_rounds', 'id', 'feedback_participants_round_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->comment('the assessed employee')->constrained('employees', 'id', 'feedback_participants_employee_fk')->cascadeOnDelete();
            $table->string('nominator', 16)->default('employee')->comment('employee|manager|role');
            $table->foreignId('nominator_user_id')->nullable()->constrained('users', 'id', 'feedback_participants_nominator_fk')->nullOnDelete();
            $table->foreignId('nominator_role_id')->nullable()->constrained('roles', 'id', 'feedback_participants_role_fk')->nullOnDelete();
            $table->string('nomination_status', 16)->default('draft')->comment('draft|submitted|approved|returned|not_needed');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users', 'id', 'feedback_participants_submitted_by_fk')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'feedback_participants_decided_by_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['feedback_round_id', 'employee_id'], 'feedback_participants_round_employee_unique');
            $table->index(['tenant_id', 'nomination_status'], 'feedback_participants_status_index');
        });

        Schema::create('feedback_nominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feedback_round_id')->constrained('feedback_rounds', 'id', 'feedback_nominations_round_fk')->cascadeOnDelete();
            $table->foreignId('feedback_participant_id')->constrained('feedback_participants', 'id', 'feedback_nominations_participant_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->comment('the assessed employee')->constrained('employees', 'id', 'feedback_nominations_employee_fk')->cascadeOnDelete();
            $table->string('relation', 24)->comment('manager|functional_manager|peer|subordinate|client');
            $table->foreignId('reviewer_employee_id')->nullable()->constrained('employees', 'id', 'feedback_nominations_reviewer_fk')->nullOnDelete();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users', 'id', 'feedback_nominations_reviewer_user_fk')->nullOnDelete();
            $table->string('external_name', 160)->nullable()->comment('a client reviewer: name (no other personal data)');
            $table->string('external_email', 191)->nullable()->comment('a client reviewer: e-mail the link is sent to');
            $table->boolean('is_anonymous')->default(false);
            $table->string('source', 16)->default('nominated')->comment('system|nominated');
            $table->string('status', 16)->default('draft')->comment('draft|requested|submitted|declined|cancelled');
            $table->foreignId('nominated_by')->nullable()->constrained('users', 'id', 'feedback_nominations_nominated_by_fk')->nullOnDelete();
            $table->string('token_hash', 64)->nullable()->comment('sha256 of the external link token (cleared once answered)');
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->date('responded_on')->nullable()->comment('date only');
            $table->timestamps();

            $table->unique('token_hash', 'feedback_nominations_token_unique');
            $table->unique(['feedback_participant_id', 'reviewer_employee_id'], 'feedback_nominations_participant_reviewer_unique');
            $table->unique(['feedback_participant_id', 'external_email'], 'feedback_nominations_participant_email_unique');
            $table->index(['tenant_id', 'reviewer_user_id', 'status'], 'feedback_nominations_reviewer_status_index');
        });

        Schema::create('feedback_responses', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('random, never ordered by time');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feedback_round_id')->constrained('feedback_rounds', 'id', 'feedback_responses_round_fk')->cascadeOnDelete();
            $table->foreignId('feedback_participant_id')->constrained('feedback_participants', 'id', 'feedback_responses_participant_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->comment('the assessed employee')->constrained('employees', 'id', 'feedback_responses_employee_fk')->cascadeOnDelete();
            $table->string('relation', 24);
            $table->foreignId('feedback_nomination_id')->nullable()->comment('only when the source is not anonymous')
                ->constrained('feedback_nominations', 'id', 'feedback_responses_nomination_fk')->nullOnDelete();
            $table->json('answers');
            $table->decimal('score', 5, 2)->nullable()->comment('0-100: the share of the scale of the rated answers');

            $table->index(['feedback_participant_id', 'relation'], 'feedback_responses_participant_relation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_responses');
        Schema::dropIfExists('feedback_nominations');
        Schema::dropIfExists('feedback_participants');
        Schema::dropIfExists('feedback_rounds');
    }
};
