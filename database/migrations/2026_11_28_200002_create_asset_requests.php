<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity AS-B — Asset requests: an employee (or their manager, or HR) asks for a new asset, a replacement or
 * a return; the asset entitlement policy raises them for joiners, and exits raise returns. A request may need approval
 * (asset.request.workflow_for), then the asset type's owner role fulfils it by the due date. `source_key` keeps the
 * requests raised automatically from being raised twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->comment('new | replacement | return');
            $table->string('source', 16)->default('employee')->comment('employee | manager | hr | policy | exit');
            $table->string('source_key', 64)->nullable()->comment('policy and exit requests: raised once per key and employee');
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_subtype_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_model_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->comment('the asset to return or replace')->constrained()->nullOnDelete();
            $table->foreignId('asset_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fulfilled_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->date('needed_by')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 16)->default('requested')->comment('requested | approved | rejected | fulfilled | cancelled');
            $table->foreignId('owner_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fulfilment_note', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'employee_id', 'source_key'], 'asset_requests_source_unique');
            $table->index(['tenant_id', 'status', 'due_on'], 'asset_requests_status_due_idx');
            $table->index(['tenant_id', 'employee_id', 'status'], 'asset_requests_emp_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_requests');
    }
};
