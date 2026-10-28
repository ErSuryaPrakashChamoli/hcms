<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C12-W: a controlled correction of an establishment statutory profile for months whose payroll is already approved or
 * finalised. The request records the original context (the profile as payroll used it and, per affected month and
 * statute, what applied before and after with the finalised runs and amounts) once; only the status moves after that.
 * Finalised payroll and built packs are never changed by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_profile_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('profile_id')->nullable()->comment('the profile changed; no foreign key, so a deleted profile keeps its correction');
            $table->string('action', 8)->comment('update|create|delete');
            $table->string('statute', 8);
            $table->json('original')->nullable()->comment('the profile row as payroll used it (null for create)');
            $table->json('proposed')->nullable()->comment('applicable, effective_from, effective_to (null for delete)');
            $table->json('affected')->comment('per month and statute: period_end, before, after, run_ids, amount');
            $table->string('classification', 24)->comment('configuration_only|payroll_impact');
            $table->json('required_actions')->nullable();
            $table->text('reason');
            $table->text('evidence')->nullable();
            $table->string('status', 16)->default('requested')->comment('requested|approved|rejected|applied');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            // Named explicitly: the generated name is longer than MySQL's 64-character identifier limit.
            $table->index(['tenant_id', 'establishment_id', 'status'], 'stat_profile_corrections_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_profile_corrections');
    }
};
