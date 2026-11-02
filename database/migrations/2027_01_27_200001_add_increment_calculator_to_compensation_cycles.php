<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| HROne parity PO-17 (matrix 25 §25.9.2, §25.14 #5): the increment calculator on compensation cycles.
|
| compensation_cycles gains the calculator's rules — who is in the plan (organisation filters and pasted codes), the
| amount basis (percent of annual CTC = today's rule, percent of fixed pay, or a flat amount), tenure proration and the
| clone source — plus the employees populate skipped and why (the error file). The CTC band × rating grid is stored in
| the existing rating_increase_percent column (a new shape; the old label → percent shape is still read as before).
| compensation_changes gains the annual CTC a cycle line was calculated from. Two new tables: the managers a plan is
| shared with, and the amounts they propose for their own team (HR accepts or declines each).
|
| Defaults reproduce the earlier behaviour: basis ctc_percent, no proration, no population filter.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compensation_cycles', function (Blueprint $table) {
            $table->json('population')->nullable()->after('location_id');
            $table->string('increment_basis', 16)->default('ctc_percent')->after('rating_increase_percent');
            $table->decimal('default_increase_amount', 14, 2)->nullable()->after('increment_basis');
            $table->boolean('prorate_on_tenure')->default(false)->after('default_increase_amount');
            $table->string('proration_basis', 16)->nullable()->after('prorate_on_tenure');
            $table->date('proration_cutoff')->nullable()->after('proration_basis');
            $table->unsignedSmallInteger('proration_full_months')->nullable()->after('proration_cutoff');
            $table->json('population_errors')->nullable()->after('snapshot_checksum');
            $table->foreignId('cloned_from_id')->nullable()->after('operation_id')
                ->constrained('compensation_cycles', indexName: 'comp_cycles_cloned_from_fk')->nullOnDelete();
        });

        Schema::table('compensation_changes', function (Blueprint $table) {
            // The annual CTC in force that a cycle line was calculated from (cycle lines only; information).
            $table->decimal('base_ctc_annual', 14, 2)->nullable()->after('increase_percent');
        });

        Schema::create('compensation_cycle_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compensation_cycle_id')->constrained(indexName: 'comp_cycle_shares_cycle_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'comp_cycle_shares_user_fk')->restrictOnDelete();
            $table->foreignId('shared_by')->constrained('users', indexName: 'comp_cycle_shares_shared_by_fk')->restrictOnDelete();
            $table->timestamp('shared_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users', indexName: 'comp_cycle_shares_revoked_by_fk')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['compensation_cycle_id', 'user_id'], 'comp_cycle_shares_cycle_user_unique');
            $table->index(['tenant_id', 'user_id', 'revoked_at'], 'comp_cycle_shares_user_index');
        });

        Schema::create('compensation_cycle_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compensation_cycle_id')->constrained(indexName: 'comp_cycle_proposals_cycle_fk')->cascadeOnDelete();
            // The cycle line; set to null when a recalculation replaces the plan's lines (the proposal then lapses).
            $table->foreignId('compensation_change_id')->nullable()->constrained(indexName: 'comp_cycle_proposals_change_fk')->nullOnDelete();
            $table->foreignId('employee_id')->constrained(indexName: 'comp_cycle_proposals_employee_fk')->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users', indexName: 'comp_cycle_proposals_proposed_by_fk')->restrictOnDelete();
            $table->decimal('ctc_annual', 14, 2);
            $table->decimal('base_ctc_annual', 14, 2);
            $table->decimal('increase_percent', 8, 2)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('proposed');
            $table->foreignId('decided_by')->nullable()->constrained('users', indexName: 'comp_cycle_proposals_decided_by_fk')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['compensation_cycle_id', 'status'], 'comp_cycle_proposals_cycle_status_index');
            $table->index(['compensation_cycle_id', 'proposed_by'], 'comp_cycle_proposals_cycle_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_cycle_proposals');
        Schema::dropIfExists('compensation_cycle_shares');
        Schema::table('compensation_changes', function (Blueprint $table) {
            $table->dropColumn('base_ctc_annual');
        });
        Schema::table('compensation_cycles', function (Blueprint $table) {
            $table->dropForeign('comp_cycles_cloned_from_fk');
        });
        Schema::table('compensation_cycles', function (Blueprint $table) {
            $table->dropColumn(['population', 'increment_basis', 'default_increase_amount', 'prorate_on_tenure', 'proration_basis', 'proration_cutoff', 'proration_full_months', 'population_errors', 'cloned_from_id']);
        });
    }
};
