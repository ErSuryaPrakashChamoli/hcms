<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity CA-6 (Core HR → Employee → Social profile access, "Allow employee to hide this section"): the
 * directory-card sections a person has hidden from colleagues (keys of peopleos.directory.sections). Null = none hidden.
 * Only sections the organisation lets employees hide (directory.sections_employee_may_hide) are honoured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->json('directory_hidden')->nullable()->after('wedding_anniversary_shared');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('directory_hidden');
        });
    }
};
