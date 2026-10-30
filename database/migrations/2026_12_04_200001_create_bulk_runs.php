<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity WO-1 (Workforce desk and bulk-action framework): one row per bulk run started from a register (the
 * person, the register, the operation, the records chosen and the counts) and one row per record of the run with its
 * outcome (done, refused with the reason, failed). The per-record rows also make a retried job skip what it already did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->comment('who started the run (it runs with their permissions and scope)');
            $table->string('register', 64)->comment('register key, e.g. employees, exit_cases');
            $table->string('operation', 64)->comment('BulkOperation key');
            $table->string('operation_label', 160);
            $table->string('selection', 16)->comment('selected|all_matching');
            $table->json('record_ids')->comment('the records chosen when the run started');
            $table->json('input')->nullable()->comment('the values asked once for the whole run');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('refused')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 16)->default('queued')->comment('queued|running|completed|failed');
            $table->string('operation_id', 26)->nullable()->comment('audit operation id shared by every audit event of the run');
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at'], 'bulk_runs_user_created_idx');
            $table->index(['tenant_id', 'register', 'created_at'], 'bulk_runs_register_created_idx');
        });

        Schema::create('bulk_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bulk_run_id')->constrained('bulk_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('record_id');
            $table->string('label', 191)->nullable()->comment('the record as it was named when acted on');
            $table->string('outcome', 16)->comment('done|refused|failed');
            $table->string('message', 500)->nullable();
            $table->timestamps();

            $table->unique(['bulk_run_id', 'record_id'], 'bulk_run_items_run_record_unique');
            $table->index(['tenant_id', 'bulk_run_id', 'outcome'], 'bulk_run_items_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_run_items');
        Schema::dropIfExists('bulk_runs');
    }
};
