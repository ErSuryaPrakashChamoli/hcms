<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 7 fix — rule identity. The Phase 5 unique key (jurisdiction, code, state, version) does not
 | stop duplicates when `state` is NULL (databases treat NULLs as distinct), so two central
 | "EPF v2" rows could coexist. A non-null `state_key` ('' for central rules) carries the identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_rules', function (Blueprint $table) {
            $table->string('state_key', 4)->default('')->after('state');
        });

        DB::table('compliance_rules')->whereNotNull('state')->update(['state_key' => DB::raw('state')]);

        Schema::table('compliance_rules', function (Blueprint $table) {
            $table->unique(['jurisdiction', 'code', 'state_key', 'version'], 'compliance_rules_identity_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_rules', function (Blueprint $table) {
            $table->dropUnique('compliance_rules_identity_key_unique');
            $table->dropColumn('state_key');
        });
    }
};
