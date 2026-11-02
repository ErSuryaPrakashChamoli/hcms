<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-35 ("Badge allocation", owner decision D-GO4 on its recommended behaviour): a badge is either given by a
 * person (counted in their monthly quota) or allocated by HR on behalf of the organisation from an upload (no giver quota;
 * the receiver's limits still apply). The ledger keeps which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recognitions', function (Blueprint $table) {
            $table->string('source', 16)->default('person')->after('giver_employee_id')->comment('person|upload');
        });
    }

    public function down(): void
    {
        Schema::table('recognitions', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
