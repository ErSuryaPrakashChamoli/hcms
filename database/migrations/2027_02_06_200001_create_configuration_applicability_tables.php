<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuration applicability and overrides (docs/architecture/configuration-applicability.md).
 *
 * - configuration_applicability: one row per configuration record whose companies were chosen explicitly (all, selected or
 *   one company, and whether companies created later join). A record without a row keeps its earlier meaning: a tenant-wide
 *   record applies to every company, a company-owned record (company_id set) to its own company.
 * - configuration_applicability_companies: which companies a record applies to, effective-dated. Rows are end-dated,
 *   never deleted, so "which companies did this apply to on a given day" always has an answer.
 * - configuration_overrides: one company's own value for one field of a shared record, effective-dated and versioned.
 *   Ending an override restores the shared value; nothing is deleted.
 *
 * No existing table changes, so rolling back drops these three tables and every record keeps working as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_applicability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('configuration_type', 64)->comment('config(peopleos.configuration_types) key');
            $table->unsignedBigInteger('configuration_id');
            $table->string('mode', 16)->comment('all | selected | single');
            $table->boolean('include_future')->default(false)->comment('companies created later join automatically');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'configuration_type', 'configuration_id'], 'cfg_applicability_record_unique');
        });

        Schema::create('configuration_applicability_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicability_id')->constrained('configuration_applicability')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from')->nullable()->comment('null = from the beginning');
            $table->date('effective_to')->nullable();
            $table->string('source', 16)->comment('earlier (reach before the first explicit choice) | chosen | future (joined as a new company)');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['applicability_id', 'company_id'], 'cfg_applicability_companies_idx');
            $table->index(['tenant_id', 'company_id'], 'cfg_applicability_company_idx');
        });

        Schema::create('configuration_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('ulid')->unique();
            $table->string('configuration_type', 64);
            $table->unsignedBigInteger('configuration_id');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('field', 191)->comment('the field, or a dotted path inside settings');
            $table->json('value')->nullable();
            $table->json('shared_value')->nullable()->comment('the shared value when the override was made, for history');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->comment('active | ended | superseded');
            $table->unsignedInteger('version');
            $table->string('reason', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'configuration_type', 'configuration_id', 'company_id'], 'cfg_overrides_record_idx');
            $table->index(['tenant_id', 'company_id', 'status'], 'cfg_overrides_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_overrides');
        Schema::dropIfExists('configuration_applicability_companies');
        Schema::dropIfExists('configuration_applicability');
    }
};
