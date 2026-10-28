<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Guided investment declaration: each tds_employee_investments row is one item of the
 | InvestmentCatalogue (item_key, e.g. "80C.ppf"), so the declaration wizard can show what the
 | employee declared under each option. details holds item extras (the landlord for rent), encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tds_employee_investments', function (Blueprint $table) {
            $table->string('item_key', 48)->nullable()->after('section');
            $table->text('details')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tds_employee_investments', function (Blueprint $table) {
            $table->dropColumn(['item_key', 'details']);
        });
    }
};
