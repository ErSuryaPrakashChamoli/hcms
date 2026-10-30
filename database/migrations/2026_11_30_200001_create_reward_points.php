<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-9d (Engage → Reward points, Custom badges → Reward applicability): badges may carry reward points
 * (and show them on the social wall), and the reward points ledger. The ledger is append-only: every deposit, credit,
 * award, redemption, lapse and adjustment is a new row (signed points) in one of three wallets — the organisation's
 * budget (no employee), an employee's points to give, and the points an employee received. A redemption through payroll
 * records the run that paid it once (the only column that changes); a cancelled redemption is reversed by a new row.
 * `reward_point_budgets` is one row per organisation that every posting against the budget locks first (so two credits
 * at once cannot both spend the last points); the balance itself is always the ledger's sum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->unsignedInteger('reward_points')->default(0)->after('core_value');
            $table->boolean('show_points_on_wall')->default(false)->after('reward_points');
        });

        Schema::create('reward_point_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('reward_point_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('wallet', 12);
            $table->string('kind', 24);
            $table->integer('points');
            $table->decimal('amount', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('cycle_key', 16)->nullable();
            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('counterpart_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->json('meta')->nullable();
            $table->string('dedupe_key', 80)->nullable();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'dedupe_key'], 'reward_points_dedupe_unique');
            $table->index(['tenant_id', 'employee_id', 'wallet'], 'reward_points_wallet_index');
            $table->index(['tenant_id', 'kind', 'payroll_run_id'], 'reward_points_kind_index');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'reward_points_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_point_entries');
        Schema::dropIfExists('reward_point_budgets');
        Schema::table('badges', function (Blueprint $table) {
            $table->dropColumn(['reward_points', 'show_points_on_wall']);
        });
    }
};
