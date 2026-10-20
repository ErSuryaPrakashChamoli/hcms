<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.9 closure (additive): the three retention inputs stay distinct.
 *
 * A. Verified legal minimum: retention_policies gains its verification (status, authoritative source, reviewer, date).
 *    An entered but unverified minimum is pending_verification, never silently a floor or a zero.
 * B. Markedge company policy: retention_policies.policy_months (unchanged), now with the event retention starts from.
 * C. Customer contractual requirement: tenant_retention_terms, per tenant and category, approved by a second operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retention_policies', function (Blueprint $table) {
            // not_configured | pending_verification | verified
            $table->string('legal_minimum_status', 24)->default('not_configured')->after('legal_minimum_months');
            $table->string('legal_source', 500)->nullable()->after('legal_reference');
            $table->string('legal_verified_by', 200)->nullable()->after('legal_source');
            $table->date('legal_verified_on')->nullable()->after('legal_verified_by');
            // tenant_closure | financial_year_end_after_closure
            $table->string('start_event', 40)->default('tenant_closure')->after('policy_basis');
            $table->char('financial_year_end', 5)->nullable()->after('start_event'); // MM-DD, for financial_year_end_after_closure
        });
        // Existing versions: a minimum entered before verification existed is pending verification, not verified.
        DB::table('retention_policies')->whereNotNull('legal_minimum_months')->update(['legal_minimum_status' => 'pending_verification']);

        Schema::create('tenant_retention_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('category', 40); // a data category, or * for every category
            $table->unsignedSmallInteger('minimum_months')->nullable(); // the customer requires Markedge to keep data at least this long
            $table->unsignedInteger('maximum_days')->nullable(); // the customer requires deletion within this many days of closure
            $table->string('contract_reference', 200);
            $table->date('effective_from');
            $table->string('status', 16); // pending | approved | rejected | withdrawn
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proposed_at');
            $table->text('reason');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'category', 'status', 'effective_from'], 'tenant_retention_terms_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_retention_terms');
        Schema::table('retention_policies', function (Blueprint $table) {
            $table->dropColumn(['legal_minimum_status', 'legal_source', 'legal_verified_by', 'legal_verified_on', 'start_event', 'financial_year_end']);
        });
    }
};
