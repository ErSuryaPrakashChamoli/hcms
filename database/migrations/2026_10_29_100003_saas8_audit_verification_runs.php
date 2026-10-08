<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.8: the result of every audit-chain verification (one row per chain per run), written by the scheduled
 * peopleos:audit:verify. The readiness page reads the latest results instead of re-hashing every audit event of every
 * tenant on each page load. Platform-level (subject_tenant_id names the chain verified; null = the platform chain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_verification_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('chain', 16); // tenant | platform
            $table->boolean('valid');
            $table->unsignedBigInteger('checked');
            $table->string('broken_event_id', 64)->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('trigger', 16); // schedule | command | readiness
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->nullable();

            $table->index(['chain', 'subject_tenant_id', 'id'], 'audit_verification_runs_latest_index');
            $table->index('finished_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_verification_runs');
    }
};
