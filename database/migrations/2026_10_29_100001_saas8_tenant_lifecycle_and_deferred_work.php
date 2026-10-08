<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.8 tenant lifecycle integrity. Additive; existing rows keep their meaning.
 *
 * - tenants.suspended_from / suspended_at: the status a tenant had when it was suspended (trial or active) and when.
 *   Reactivation restores that status instead of always writing `active`. Tenants suspended before this migration
 *   have no recorded status and reactivate as `active`, as before.
 * - tenant_deferred_jobs: queued work that reached a worker while its tenant was suspended. It was dropped (the job
 *   was acknowledged and lost); it is now kept, encrypted, and dispatched again when the tenant is reactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('suspended_from', 16)->nullable()->after('status');
            $table->timestamp('suspended_at')->nullable()->after('suspended_from');
        });

        Schema::create('tenant_deferred_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('job_class', 255);
            $table->longText('payload'); // the serialized job, encrypted with the application key
            $table->string('connection', 64)->nullable();
            $table->string('queue', 128)->nullable();
            $table->timestamp('deferred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_deferred_jobs');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['suspended_from', 'suspended_at']);
        });
    }
};
