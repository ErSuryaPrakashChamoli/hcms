<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.3 entitlement engine (shadow mode). No plan, subscription, price, invoice, payment or billing table.
 *
 * - tenant_entitlement_profiles: one row per tenant: commercial state (unconfigured / configured), the business
 *   date the configuration starts, a version bumped by every change, and the row configuration changes lock.
 * - tenant_entitlements: the tenant's commercial configuration, one effective-dated value per capability at a time.
 * - entitlement_overrides: explicit, reasoned platform exceptions, effective-dated, revocable; they win over the
 *   configuration.
 * - entitlement_shadow_observations: aggregated shadow decisions (per tenant, day, capability, outcome, reason and
 *   surface), written off the request path and throttled, never one row per check.
 *
 * Active configuration and override rows may not share a start date for one capability: a generated column holds
 * the start date only while the row is active, under a unique index (overlaps are refused by the services).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_entitlement_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('state', 16)->default('unconfigured');
            $table->date('configured_from')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (['tenant_entitlements' => 'ten_ent', 'entitlement_overrides' => 'ent_ovr'] as $name => $short) {
            Schema::create($name, function (Blueprint $table) use ($short) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('capability', 64);
                $table->boolean('value_bool')->nullable();
                $table->unsignedBigInteger('value_int')->nullable(); // limits: null = unlimited
                $table->date('effective_from');
                $table->date('effective_to')->nullable(); // inclusive; null = open-ended
                $table->string('status', 16)->default('active'); // active | cancelled (never took effect)
                $table->string('reason', 500);
                $table->string('reference', 100)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('closed_at')->nullable();
                $table->string('close_reason', 500)->nullable();
                $table->unsignedBigInteger('superseded_by')->nullable();
                $table->date('active_from')->nullable()->virtualAs("CASE WHEN status = 'active' THEN effective_from END");
                $table->timestamps();

                $table->unique(['tenant_id', 'capability', 'active_from'], "{$short}_active_unique");
                $table->index(['tenant_id', 'capability', 'effective_from'], "{$short}_lookup_index");
            });
        }

        Schema::create('entitlement_shadow_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('observed_on');
            $table->string('capability', 64);
            $table->string('outcome', 16);
            $table->string('reason', 32);
            $table->string('surface', 64);
            $table->unsignedBigInteger('occurrences')->default(0); // a lower bound (sampled across requests)
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('last_source', 16);
            $table->unsignedBigInteger('last_entitlement_id')->nullable();
            $table->unsignedBigInteger('last_override_id')->nullable();

            $table->unique(['tenant_id', 'observed_on', 'capability', 'outcome', 'reason', 'surface'], 'ent_shadow_obs_unique');
            $table->index(['observed_on', 'outcome'], 'ent_shadow_obs_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_shadow_observations');
        Schema::dropIfExists('entitlement_overrides');
        Schema::dropIfExists('tenant_entitlements');
        Schema::dropIfExists('tenant_entitlement_profiles');
    }
};
