<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-4 (Payroll): two more kinds of tax_income_entries, both read by TDS through TaxIncomeEntries.
 * - previous_salary: a month paid before PeopleOS went live this financial year (Previous month salary upload); the
 *   month, and its basic + DA and HRA so the HRA exemption counts the whole year;
 * - tax_credit: tax deducted or collected at source elsewhere that the employee declares (Form 12BAA): TDS or TCS,
 *   the provision, the deductor and their TAN. Counted by TDS only when the TDS rule in force allows it.
 * Every column is nullable: existing rows (perquisite, outside_payroll) are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_income_entries', function (Blueprint $table) {
            $table->date('pay_month')->nullable()->after('paid_on')->comment('previous_salary: first day of the month the salary was for');
            $table->decimal('basic_da', 14, 2)->nullable()->after('professional_tax')->comment('previous_salary: basic + DA paid');
            $table->decimal('hra', 14, 2)->nullable()->after('basic_da')->comment('previous_salary: HRA paid');
            $table->string('credit_type', 8)->nullable()->after('hra')->comment('tax_credit: tds|tcs');
            $table->string('section', 24)->nullable()->after('credit_type')->comment('tax_credit: provision deducted or collected under');
            $table->string('deductor_name', 150)->nullable()->after('section');
            $table->string('deductor_tan', 10)->nullable()->after('deductor_name');
        });
    }

    public function down(): void
    {
        Schema::table('tax_income_entries', function (Blueprint $table) {
            $table->dropColumn(['pay_month', 'basic_da', 'hra', 'credit_type', 'section', 'deductor_name', 'deductor_tan']);
        });
    }
};
