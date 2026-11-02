<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity GO-18 (Engage → "Initiate fundraise", matrix 27 §27.8.9): fundraiser campaigns and the pledges employees
 * make to them. A campaign has a cause (name, purpose, beneficiary, a supporting document on the private documents
 * disk), a window, a target in one currency and an audience (a saved audience or criteria, pinned with the opener's scope
 * when it opens); status draft → open → closed, or cancelled. A pledge is one employee's amount, paid outside payroll or —
 * only while the organisation allows it (engagement.fundraisers.payroll_deduction, off) and the campaign collects through
 * payroll — deducted once in the payroll month chosen, with the employee's consent recorded on the pledge. Nothing is
 * deleted: pledges are cancelled; a deduction records the payroll run that took it, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fundraiser_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('purpose');
            $table->string('beneficiary', 200)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('target_amount', 14, 2);
            $table->char('currency', 3);
            $table->string('document_path', 500)->nullable();
            $table->string('document_name', 255)->nullable();
            $table->foreignId('audience_id')->nullable()->constrained('audiences')->nullOnDelete();
            $table->json('audience_criteria')->nullable();
            $table->foreignId('scope_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('payroll_deduction')->default(false);
            $table->string('deduction_month', 7)->nullable();
            $table->string('status', 12)->default('draft');
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'ends_on'], 'fundraiser_campaigns_status_index');
        });

        Schema::create('fundraiser_pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fundraiser_campaign_id')->constrained('fundraiser_campaigns', indexName: 'fundraiser_pledges_campaign_foreign')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('mode', 20);
            $table->string('payroll_month', 7)->nullable();
            $table->timestamp('consent_given_at')->nullable();
            $table->string('consent_text', 500)->nullable();
            $table->string('status', 12)->default('pledged');
            $table->string('note', 500)->nullable();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('deducted_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'fundraiser_campaign_id', 'status'], 'fundraiser_pledges_campaign_status_index');
            $table->index(['tenant_id', 'employee_id', 'mode', 'status'], 'fundraiser_pledges_employee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fundraiser_pledges');
        Schema::dropIfExists('fundraiser_campaigns');
    }
};
