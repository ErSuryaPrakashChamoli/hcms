<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuration cloning, phase 1 (docs/architecture/configuration-cloning.md): one row per clone attempt (what was copied,
 * from where, to which companies, every dependency decision and the outcome) and one lineage row per record a clone
 * created, reused or mapped. Both are append-only records; no existing configuration table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_clone_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('ulid')->unique();
            // One wizard instance = one key. Released (set to null) when an attempt fails or is refused, so the same
            // wizard can try again once the problem is fixed.
            $table->string('idempotency_key', 64)->nullable();
            $table->string('adapter', 64);
            $table->string('mode', 16);
            $table->unsignedBigInteger('source_id');
            $table->string('source_label');
            $table->json('source_company_ids')->nullable(); // null = the source applies to every company
            $table->string('source_checksum', 64);
            $table->json('source_snapshot');
            $table->json('destination_company_ids');
            $table->json('plan');
            $table->json('result')->nullable();
            $table->string('status', 16); // running | completed | failed | refused
            $table->text('error')->nullable();
            $table->string('audit_operation_id', 26)->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'adapter', 'source_id']);
            $table->index(['tenant_id', 'status', 'started_at']);
        });

        Schema::create('configuration_lineage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operation_id')->constrained('configuration_clone_operations')->cascadeOnDelete();
            $table->string('adapter', 64); // the root's adapter key, or what a dependency row stands for (policy.rule, department_id, leave_types)
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('destination_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('role', 16); // root | dependency
            $table->string('action', 16); // cloned | reused | mapped
            $table->string('source_checksum', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'adapter', 'source_id']);
            $table->index(['tenant_id', 'adapter', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_lineage');
        Schema::dropIfExists('configuration_clone_operations');
    }
};
