<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity RQ-8 (Inbox → Insight): one row per decision a person took on a domain request in the Approval Center
 * (the Approvals page, the drawer, the unified inbox), with the due time the item showed then — what the approver's own
 * "Insight" (decided, on time, delayed) is counted from. Workflow steps are counted from workflow_tasks instead. The
 * decision itself is audited by the request's own service; this row is derived and append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('who decided (the delegate when on behalf of someone)');
            $table->foreignId('on_behalf_of_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('item_id', 80)->comment('ApprovalItem id, e.g. leave:12');
            $table->string('item_type', 40)->comment('ApprovalCenter::kinds() type');
            $table->string('decision', 32);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'decided_at'], 'inbox_decisions_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_decisions');
    }
};
