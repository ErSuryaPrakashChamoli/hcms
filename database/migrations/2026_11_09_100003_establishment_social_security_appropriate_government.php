<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The appropriate government can differ by Code. Under the Code on Wages (and the OSH Code) a private shop, office or
 * factory is normally State-sphere, but under the Code on Social Security, 2020 s.2(3)(a) an establishment "having
 * departments or branches in more than one State" has the Central Government as its appropriate government (gratuity,
 * maternity benefit, employment information). establishments.appropriate_government keeps the Wages / OSH answer;
 * ss_appropriate_government holds the Social Security one. Nullable: a missing value is configuration required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->string('ss_appropriate_government', 16)->nullable()->after('appropriate_government')->comment('central|state, under the Code on Social Security');
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn('ss_appropriate_government');
        });
    }
};
