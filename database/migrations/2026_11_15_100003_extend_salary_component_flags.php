<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity — Payroll → Pay component: which statutory wages a component is part of (null = derived as today:
 * PT and LWF wages = part of gross, ESI eligibility = ESI wages), whether it shows on the payslip and register, is paid
 * in the joining and exit months, how it is rounded, its nature, its tax exemption by regime, and claim rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->boolean('pt_wages')->nullable()->after('esi_applicable');
            $table->boolean('lwf_wages')->nullable()->after('pt_wages');
            $table->boolean('esi_eligibility_wages')->nullable()->after('lwf_wages');
            $table->boolean('show_on_payslip')->default(true)->after('include_in_gross');
            $table->boolean('show_in_register')->default(true)->after('show_on_payslip');
            $table->boolean('pay_in_joining_month')->default(true)->after('is_proratable');
            $table->boolean('pay_in_exit_month')->default(true)->after('pay_in_joining_month');
            $table->string('rounding', 16)->nullable()->after('pay_in_exit_month')->comment('null|nearest_1|up_1|nearest_10');
            $table->string('pay_nature', 16)->default('fixed')->after('rounding')->comment('fixed|variable');
            $table->json('exempt_in_regimes')->nullable()->after('taxable')->comment('regimes in which a non-taxable component is exempt; null = all');
            $table->boolean('claim_attachment_required')->nullable()->after('pay_on_claim');
            $table->unsignedSmallInteger('claim_window_days')->nullable()->after('claim_attachment_required');
            $table->decimal('claim_limit_per_claim', 14, 2)->nullable()->after('claim_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('salary_components', fn (Blueprint $table) => $table->dropColumn(['pt_wages', 'lwf_wages', 'esi_eligibility_wages', 'show_on_payslip', 'show_in_register', 'pay_in_joining_month',
            'pay_in_exit_month', 'rounding', 'pay_nature', 'exempt_in_regimes', 'claim_attachment_required', 'claim_window_days', 'claim_limit_per_claim']));
    }
};
