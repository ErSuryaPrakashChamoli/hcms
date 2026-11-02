<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-2 (Workforce → Family details / Children / Dependents): a family member's occupation, as HROne's family
 * import carries it. Null on every existing member and optional everywhere, so every screen stays as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_family_members', function (Blueprint $table) {
            $table->string('occupation', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('person_family_members', function (Blueprint $table) {
            $table->dropColumn('occupation');
        });
    }
};
