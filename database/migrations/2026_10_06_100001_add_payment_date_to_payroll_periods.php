<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 7 — salary TDS follows the date of payment (Income-tax Act, 2025 s.392(1); ITD TDS
 | Compliance FAQs). A payroll period may record its salary payment date; without one the period
 | end date is used, which reproduces every earlier calculation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->date('payment_date')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', fn (Blueprint $table) => $table->dropColumn('payment_date'));
    }
};
