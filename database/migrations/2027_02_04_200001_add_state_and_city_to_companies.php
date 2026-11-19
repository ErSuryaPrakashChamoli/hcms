<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company's state (its code on the platform's ISO 3166-2 region list, e.g. MH) and city, asked for when its country
 * uses them (App\Support\Geography\AddressRules). Both nullable: existing companies keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('state_code', 16)->nullable()->after('country_code')->comment('country_regions.code');
            $table->string('city', 120)->nullable()->after('state_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['state_code', 'city']);
        });
    }
};
