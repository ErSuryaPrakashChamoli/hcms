<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-9a (Engage → Celebration): work and wedding anniversaries beside birthdays. A person may record
 * a wedding anniversary (day and month only, like the celebrated birthday; kept from colleagues unless shared). Wishes
 * carry their kind, so a colleague may wish someone once a year for each occasion. The new unique key is created before
 * the old one is dropped: on MySQL the old one serves the employee foreign key until another index starting with
 * employee_id exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->unsignedTinyInteger('wedding_anniversary_month')->nullable()->after('birthday_shared');
            $table->unsignedTinyInteger('wedding_anniversary_day')->nullable()->after('wedding_anniversary_month');
            $table->boolean('wedding_anniversary_shared')->default(false)->after('wedding_anniversary_day');

            $table->index(['tenant_id', 'wedding_anniversary_month', 'wedding_anniversary_day'], 'people_wedding_anniversary_index');
        });

        Schema::table('birthday_wishes', function (Blueprint $table) {
            $table->string('kind', 24)->default('birthday')->after('sender_employee_id');
        });
        Schema::table('birthday_wishes', function (Blueprint $table) {
            $table->unique(['employee_id', 'sender_user_id', 'year', 'kind'], 'birthday_wishes_once_per_kind_unique');
        });
        Schema::table('birthday_wishes', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'sender_user_id', 'year']);
        });
    }

    public function down(): void
    {
        // Anniversary wishes have no place in the earlier schema (one wish per sender, recipient and year).
        DB::table('birthday_wishes')->where('kind', '!=', 'birthday')->delete();
        Schema::table('birthday_wishes', function (Blueprint $table) {
            $table->unique(['employee_id', 'sender_user_id', 'year']);
        });
        Schema::table('birthday_wishes', function (Blueprint $table) {
            $table->dropUnique('birthday_wishes_once_per_kind_unique');
        });
        Schema::table('birthday_wishes', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex('people_wedding_anniversary_index');
            $table->dropColumn(['wedding_anniversary_month', 'wedding_anniversary_day', 'wedding_anniversary_shared']);
        });
    }
};
