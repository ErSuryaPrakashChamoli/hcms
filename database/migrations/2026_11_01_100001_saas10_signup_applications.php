<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.10: self-service signup applications. A platform table (no tenant_id: the application exists before its
 * tenant); the tenant it became is `provisioned_tenant_id` (invariant 22 reads tenant_id as ownership). Additive:
 * existing tables are unchanged.
 *
 * - One open application per e-mail address: `open_email_hash` is the address hash only while the application is
 *   open, and unique.
 * - The e-mail link is stored as its SHA-256 only (`token_hash`), single-use, with an expiry.
 * - Provisioning: `attempts`, `claim_token` (the fencing token of the run that owns it), `claimed_at` (lease),
 *   `next_attempt_at` (backoff), `failure_code` / `failure_summary` (safe text only).
 * - Personal data (contact name, e-mail) is removed from the application when the owner activates their account;
 *   the account in the tenant holds it from then on, under SaaS.9's deletion controls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_applications', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 26)->unique();
            $table->string('status', 32);
            $table->string('email', 254)->nullable();
            $table->char('email_hash', 64)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('company_name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->string('display_name', 120)->nullable();
            $table->char('country_code', 2);
            $table->string('timezone', 64)->nullable();
            $table->unsignedInteger('intended_employees')->nullable();
            $table->string('plan_code', 64);
            $table->foreignId('plan_version_id')->nullable()->constrained('plan_versions')->restrictOnDelete();
            $table->foreignId('billing_market_id')->nullable()->constrained('billing_markets')->restrictOnDelete();
            $table->unsignedSmallInteger('trial_days')->nullable();
            $table->string('terms_version', 32)->nullable();
            $table->string('privacy_version', 32)->nullable();
            $table->boolean('authority_confirmed')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_ip', 45)->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_sent_at')->nullable();
            $table->unsignedSmallInteger('tokens_sent')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('review_required')->default(false);
            $table->json('review_reasons')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->char('claim_token', 26)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('failure_code', 48)->nullable();
            $table->string('failure_summary', 255)->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('provisioned_tenant_id')->nullable()->unique()->constrained('tenants')->restrictOnDelete();
            $table->foreignId('provisioned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->unsignedTinyInteger('invitation_failures')->default(0);
            $table->timestamp('invitation_attempted_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->char('open_email_hash', 64)->nullable()->virtualAs("CASE WHEN status IN ('email_pending', 'details_pending', 'review_pending', 'approved', 'provisioning', 'failed_retryable', 'failed_manual_review') THEN email_hash END");
            $table->timestamps();

            $table->unique('open_email_hash', 'signup_applications_open_email_unique');
            $table->index('email_hash', 'signup_applications_email_hash_index');
            $table->index(['status', 'next_attempt_at'], 'signup_applications_status_next_index');
            $table->index(['status', 'expires_at'], 'signup_applications_status_expires_index');
            $table->index(['status', 'claimed_at'], 'signup_applications_status_claimed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_applications');
    }
};
