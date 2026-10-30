<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity 08.6 (Workforce → Transfer allowances): one-off earnings marked as transfer allowances (the
 * component's name is the label), the amounts granted on a transfer request, and the payroll run that paid them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->boolean('transfer_allowance')->default(false)->after('pay_on_claim');
        });

        Schema::table('transfer_requests', function (Blueprint $table) {
            $table->json('allowances')->nullable()->after('new_manager_id')->comment('[{salary_component_id, amount}]');
            $table->unsignedBigInteger('allowances_payroll_run_id')->nullable()->after('allowances')->comment('the finalized run that paid them');
            $table->timestamp('allowances_paid_at')->nullable()->after('allowances_payroll_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_requests', fn (Blueprint $table) => $table->dropColumn(['allowances', 'allowances_payroll_run_id', 'allowances_paid_at']));
        Schema::table('salary_components', fn (Blueprint $table) => $table->dropColumn('transfer_allowance'));
    }
};
