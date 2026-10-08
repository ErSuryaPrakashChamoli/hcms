<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.6 commercial subscriptions and trials (no money). Additive only; no row is written: no tenant receives a
 * subscription, a trial or a plan until a platform operator creates one.
 *
 * - tenant_subscriptions: a commercial agreement's identity (tenant-owned, like the plan assignment it drives).
 * - subscription_periods: its effective-dated timeline. One row per span with one commercial state (trial, active,
 *   grace, expired, cancelled) and the plan version in force; inclusive business dates (UTC); never deleted. A row
 *   that never took effect is voided; a generated column under a unique index keeps one live row per start date
 *   within a subscription.
 * - tenant_plan_assignments.subscription_id / commercial_status: the assignments a subscription projected, and the
 *   state each represents (the engine keeps reading assignments only).
 * - tenant_entitlement_profiles.subscription_managed: once a tenant has a subscription, its plan is written only by
 *   the subscription service (manual assignment is refused).
 * - entitlement_shadow_observations.last_commercial_status: shadow context only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500);
            $table->string('reference', 100)->nullable(); // contract, order or ticket
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'id'], 'tenant_subscriptions_tenant_index');
        });

        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('tenant_subscriptions')->cascadeOnDelete();
            $table->string('status', 16); // trial | active | grace | expired | cancelled
            $table->foreignId('plan_version_id')->constrained();
            $table->date('starts_on');
            $table->date('ends_on')->nullable(); // inclusive; null = open-ended
            $table->string('trigger', 16)->default('operator'); // operator | scheduler
            $table->string('reason', 500);
            $table->string('reference', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 500)->nullable();
            $table->timestamp('voided_at')->nullable(); // never took effect (replaced before it started)
            $table->unsignedBigInteger('superseded_by')->nullable();
            $table->date('live_from')->nullable()->virtualAs('CASE WHEN voided_at IS NULL THEN starts_on END');
            $table->timestamps();

            // One live period per start date within a subscription (the service keeps them contiguous and non-overlapping;
            // across a tenant's subscriptions it allows a new one only from the day the previous one is cancelled).
            $table->unique(['subscription_id', 'live_from'], 'sub_periods_live_unique');
            $table->index(['tenant_id', 'starts_on'], 'sub_periods_tenant_index');
            $table->index(['subscription_id', 'starts_on'], 'sub_periods_timeline_index');
            $table->index(['status', 'ends_on'], 'sub_periods_due_index');
        });

        Schema::table('tenant_plan_assignments', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->after('plan_version_id')->constrained('tenant_subscriptions')->nullOnDelete();
            $table->string('commercial_status', 16)->nullable()->after('subscription_id');
        });

        Schema::table('tenant_entitlement_profiles', function (Blueprint $table) {
            $table->boolean('subscription_managed')->default(false)->after('has_plan_assignments');
        });

        Schema::table('entitlement_shadow_observations', function (Blueprint $table) {
            $table->string('last_commercial_status', 16)->nullable()->after('last_assignment_id');
        });
    }

    public function down(): void
    {
        Schema::table('entitlement_shadow_observations', function (Blueprint $table) {
            $table->dropColumn('last_commercial_status');
        });
        Schema::table('tenant_entitlement_profiles', function (Blueprint $table) {
            $table->dropColumn('subscription_managed');
        });
        Schema::table('tenant_plan_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropColumn('commercial_status');
        });
        Schema::dropIfExists('subscription_periods');
        Schema::dropIfExists('tenant_subscriptions');
    }
};
