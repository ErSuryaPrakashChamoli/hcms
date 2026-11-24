<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Name-based import resolution (docs/architecture/name-resolution.md): a value someone typed in a file (for example
 * "Human Resource") that an authorised person confirmed means one existing record (the department "Human Resources").
 *
 * - scope null = remembered for every later upload of the organisation; "employee_imports:42" = that upload only.
 * - company_id null = whichever company the row names; otherwise only rows of that company.
 * - Mappings are retired, never deleted, so the history of what a value meant is kept. `active_key` holds
 *   master|company|value|scope while the mapping is active and null once retired: one active mapping per value
 *   (NULLs do not collide in a unique index on MySQL or SQLite).
 *
 * No existing table changes; rolling back drops this table and imports fall back to codes and exact names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_reference_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('master', 64)->comment('config(peopleos.configuration_types) key, or company / employee');
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('value', 191)->comment('as typed in the file');
            $table->string('value_key', 191)->comment('normalised: NFKC, spaces collapsed, quotes and dashes unified, case-folded');
            $table->unsignedBigInteger('record_id');
            $table->string('scope', 64)->nullable()->comment('null = every upload; otherwise one upload, e.g. employee_imports:42');
            $table->string('status', 16)->comment('active | retired');
            $table->string('active_key', 255)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'active_key'], 'master_ref_mappings_active_unique');
            $table->index(['tenant_id', 'master', 'status'], 'master_ref_mappings_master_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_reference_mappings');
    }
};
