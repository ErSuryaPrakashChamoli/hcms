<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity P3 (Inbox actions): the discussion on a request waiting for approval (task_comments: one thread
 * per workflow run or per domain request, immutable) and the send-backs to the requester (request_returns: open
 * until the requester resubmits; a workflow run returned to its initiator, or a domain request that keeps its pending
 * status meanwhile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->string('kind', 40)->comment('ApprovalCenter::kinds() type the comment was posted on');
            $table->foreignId('user_id')->constrained('users');
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'commentable_type', 'commentable_id'], 'task_comments_subject_idx');
        });

        Schema::create('request_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40)->comment('ApprovalCenter::kinds() type that was sent back');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label')->nullable();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->constrained('users');
            $table->timestamp('returned_at');
            $table->text('note');
            $table->string('status', 16)->default('open')->comment('open|resubmitted|closed');
            $table->text('response')->nullable();
            $table->foreignId('resubmitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'subject_type', 'subject_id', 'status'], 'request_returns_subject_idx');
            $table->index(['tenant_id', 'requester_id', 'status'], 'request_returns_requester_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_returns');
        Schema::dropIfExists('task_comments');
    }
};
