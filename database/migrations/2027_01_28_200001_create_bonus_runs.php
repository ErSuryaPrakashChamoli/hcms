<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity PO-22 (matrix 25 §25.9.3, "Process bonus"): a statutory bonus run per company and accounting year, and its
 * lines — one per employee with finalized payroll in the year. Every statutory figure on a line (eligibility, wages for
 * bonus after the calculation ceiling, the minimum and maximum rate, the minimum amount, minimum days, the payment deadline)
 * comes from the VERIFIED BONUS rule versions recorded on the run; HR chooses only the employer's percentage within the
 * rule's range (setting `payroll.bonus.percent`), a pay-rate override with a reason, and an ex gratia amount. A line moves
 * pending → submitted (for approval, with a payroll month) → processed (approved by a second person) → paid (settled when
 * that payroll is finalized, through the `bonus` payroll input); a processed line not yet paid can be unprocessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('financial_year', 7)->comment('accounting year, e.g. 2025-26');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('bonus_rate', 9, 6)->comment('employer bonus rate used (fraction), within the BONUS rule range');
            $table->string('rate_source', 16)->comment('rule_minimum|setting');
            $table->json('rules')->comment('the BONUS rule versions used, month by month (id, version, checksum)');
            $table->date('payment_deadline')->nullable()->comment('from the rule (payment_within_months after the year)');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('recalculated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'company_id', 'financial_year'], 'bonus_runs_tenant_company_year_uq');
        });

        Schema::create('bonus_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bonus_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('eligibility', 20)->comment('eligible|review|ineligible|insufficient_data');
            $table->string('reason', 500)->nullable();
            $table->string('pay_group', 160)->nullable()->comment('code of the salary structure in force at the year end');
            $table->unsignedTinyInteger('months')->default(0);
            $table->string('from_month', 7)->nullable();
            $table->string('to_month', 7)->nullable();
            $table->decimal('bonus_days', 8, 2)->default(0);
            $table->decimal('wages', 14, 2)->default(0)->comment('basic + DA earned in the year');
            $table->decimal('bonus_wages', 14, 2)->nullable()->comment('wages for bonus after the calculation ceiling (or the pay rate override)');
            $table->decimal('pay_rate', 12, 2)->nullable()->comment('the monthly wage the bonus is calculated as if (calculation ceiling or override)');
            $table->decimal('pay_rate_override', 12, 2)->nullable();
            $table->string('override_reason', 500)->nullable();
            $table->decimal('bonus_rate', 9, 6)->default(0);
            $table->decimal('statutory_bonus', 14, 2)->default(0)->comment('bonus at the rate, at least the rule minimum amount');
            $table->decimal('already_paid', 14, 2)->default(0)->comment('bonus-classified earnings already paid for the year');
            $table->decimal('bonus_amount', 14, 2)->default(0)->comment('statutory bonus still to pay (included lines only)');
            $table->decimal('ex_gratia', 14, 2)->default(0);
            $table->string('ex_gratia_reason', 500)->nullable();
            $table->boolean('included')->default(false);
            $table->string('include_reason', 500)->nullable();
            $table->string('status', 16)->default('pending')->comment('pending|submitted|processed|paid');
            $table->string('pay_month', 7)->nullable()->comment('Y-m of the payroll month it is paid with');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('return_note', 500)->nullable();
            $table->unsignedBigInteger('paid_payroll_run_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('paid_bonus', 14, 2)->nullable();
            $table->decimal('paid_ex_gratia', 14, 2)->nullable();
            $table->json('basis')->nullable()->comment('month by month wages, full-month wages and ceilings; rule refs');
            $table->timestamps();

            $table->unique(['bonus_run_id', 'employee_id'], 'bonus_run_lines_run_employee_uq');
            $table->index(['tenant_id', 'employee_id', 'status'], 'bonus_run_lines_employee_status_idx');
            $table->foreign('paid_payroll_run_id', 'bonus_run_lines_paid_run_fk')->references('id')->on('payroll_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_run_lines');
        Schema::dropIfExists('bonus_runs');
    }
};
