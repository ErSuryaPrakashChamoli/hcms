<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remediation I.7 and I.13: each pre-flight of an employment data requirements policy is a run with a status, so a
 * large organisation's pre-flight can run on the queue (pending → running → completed / failed / superseded /
 * cancelled) and a company's coverage after its own values end can be checked and recorded.
 *
 * - purpose: review (the page's check of the policy), switch (the settings "Switch to Block" publishes), company (one
 *   company's settings after its own values end; settings holds them, end_paths what is ended once it is safe).
 * - fingerprint: the evaluated settings; evidence_fingerprint: the same with every rule that is on read as Warn (the
 *   counts do not depend on Warn or Block); applicability_ and overrides_fingerprint: who the policy reached and the
 *   companies' own values when it was requested. A run whose fingerprints are no longer current authorises nothing.
 * - report: the outcome (rules, people reached, companies, each company's settings fingerprint).
 *
 * Nothing existing changes; rolling back drops the table and the pre-flight runs inside the request again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_requirement_preflights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('ulid')->unique();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete()->comment('purpose company: the company covered');
            $table->string('purpose', 16)->comment('review | switch | company');
            $table->string('mode', 8)->comment('sync (in the request) | queued');
            $table->string('status', 16)->comment('pending | running | completed | failed | superseded | cancelled');
            $table->json('settings')->comment('the settings evaluated');
            $table->json('end_paths')->nullable()->comment('purpose company: the own values ended once the run finds it safe');
            $table->boolean('accept_gaps')->default(false);
            $table->char('fingerprint', 64);
            $table->char('evidence_fingerprint', 64);
            $table->char('applicability_fingerprint', 64);
            $table->char('overrides_fingerprint', 64);
            $table->date('effective_on');
            $table->unsignedInteger('total')->nullable()->comment('employees to read');
            $table->unsignedInteger('processed')->default(0);
            $table->json('report')->nullable();
            $table->string('outcome', 32)->nullable()->comment('purpose company: covered | refused | ended_uncovered');
            $table->text('error')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'policy_id', 'status'], 'erp_preflights_policy_status_idx');
            $table->index(['tenant_id', 'policy_id', 'company_id', 'purpose'], 'erp_preflights_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_requirement_preflights');
    }
};
