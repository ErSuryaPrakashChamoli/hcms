<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity BP-9 (variable payment schedule): an employee's planned variable pay on one earning component, by
 * month with a target. The manager's payout for a month becomes an ordinary variable pay request that names its plan
 * and month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variable_pay_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->string('name', 120)->nullable();
            $table->json('schedule')->comment('[{month: YYYY-MM, target}]');
            $table->string('status', 16)->default('active')->comment('active|closed');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status'], 'variable_pay_plans_tenant_employee_status_idx');
        });

        Schema::table('variable_pay_requests', function (Blueprint $table) {
            $table->foreignId('variable_pay_plan_id')->nullable()->after('salary_component_id')->constrained('variable_pay_plans')->restrictOnDelete();
            $table->string('plan_month', 7)->nullable()->after('variable_pay_plan_id')->comment('the plan month this payout is for');
        });
    }

    public function down(): void
    {
        Schema::table('variable_pay_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variable_pay_plan_id');
            $table->dropColumn('plan_month');
        });
        Schema::dropIfExists('variable_pay_plans');
    }
};
