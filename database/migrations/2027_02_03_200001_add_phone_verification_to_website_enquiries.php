<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Website enquiries: when the visitor's mobile number was verified with a one-time code (null: not verified). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_enquiries', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('website_enquiries', function (Blueprint $table) {
            $table->dropColumn('phone_verified_at');
        });
    }
};
