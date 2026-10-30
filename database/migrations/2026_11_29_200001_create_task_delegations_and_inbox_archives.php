<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity P4 (Inbox → Delegate, Archive inbox): out-of-office delegations (a person's approvals decided by
 * another person for a period, in the delegator's stead), each person's archived Approval Center items (hidden from
 * "Waiting for me" and the counts, never decided), and the delegate who decided a workflow task on behalf of its
 * assignee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delegator_id')->constrained('users');
            $table->foreignId('delegate_id')->constrained('users');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('types')->nullable()->comment('ApprovalCenter::kinds() types delegated; empty = every type switched on');
            $table->string('reason', 500);
            $table->string('status', 16)->default('active')->comment('active|revoked');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'delegate_id', 'status'], 'task_delegations_delegate_idx');
            $table->index(['tenant_id', 'delegator_id', 'status'], 'task_delegations_delegator_idx');
        });

        Schema::create('inbox_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_id', 80)->comment('ApprovalItem id, e.g. leave:12');
            $table->string('item_type', 40)->comment('ApprovalCenter::kinds() type');
            $table->string('title')->nullable();
            $table->timestamp('archived_at');
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete()->comment('null = archived automatically');
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'item_id', 'item_type'], 'inbox_archives_item_unique');
        });

        Schema::table('workflow_tasks', function (Blueprint $table) {
            $table->foreignId('delegate_id')->nullable()->after('completed_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delegate_id');
        });
        Schema::dropIfExists('inbox_archives');
        Schema::dropIfExists('task_delegations');
    }
};
