<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3: additive leave foundation — type attributes, cancellation review, API idempotency, ledger operation ids. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('unit', 8)->default('days')->after('category')->comment('days|hours');
            $table->decimal('min_request_units', 6, 2)->nullable()->after('unit');
            $table->decimal('max_request_units', 6, 2)->nullable()->after('min_request_units');
            $table->boolean('requires_document')->default(false)->after('is_encashable');
            $table->boolean('requires_approval')->default(true)->after('requires_document');
            $table->string('cancellation_policy', 16)->default('self')->after('requires_approval')->comment('self|approval|not_allowed (after approval)');
            $table->date('effective_from')->nullable()->after('status');
            $table->date('effective_to')->nullable()->after('effective_from');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('contact_details')->nullable()->after('reason');
            $table->timestamp('cancel_requested_at')->nullable()->after('cancelled_at');
            $table->string('cancel_reason')->nullable()->after('cancel_requested_at');
            $table->foreignId('cancellation_reviewed_by')->nullable()->after('cancel_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('cancellation_reviewed_at')->nullable()->after('cancellation_reviewed_by');
            $table->string('idempotency_key', 64)->nullable()->after('cancellation_reviewed_at');
            $table->unique(['tenant_id', 'employee_id', 'idempotency_key'], 'leave_requests_idempotency_unique');
            $table->index(['tenant_id', 'employee_id', 'status'], 'leave_requests_employee_status_idx');
        });

        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->string('operation_id', 26)->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('leave_ledger_entries', fn (Blueprint $table) => $table->dropColumn('operation_id'));
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropUnique('leave_requests_idempotency_unique');
            $table->dropIndex('leave_requests_employee_status_idx');
            $table->dropConstrainedForeignId('cancellation_reviewed_by');
            $table->dropColumn(['contact_details', 'cancel_requested_at', 'cancel_reason', 'cancellation_reviewed_at', 'idempotency_key']);
        });
        Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn(['description', 'unit', 'min_request_units', 'max_request_units', 'requires_document', 'requires_approval', 'cancellation_policy', 'effective_from', 'effective_to']));
    }
};
