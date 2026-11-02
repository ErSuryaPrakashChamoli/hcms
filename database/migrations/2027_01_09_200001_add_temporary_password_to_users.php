<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary passwords (TemporaryPasswords): while temporary_password_until is set the account's password was generated
 * for the person, works until then, and must be replaced at the first sign-in; temporary_password_via says how it reached
 * them (email: signing in with it proves the address; shown: handed over by an administrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('temporary_password_until')->nullable()->after('password_changed_at');
            $table->string('temporary_password_via', 16)->nullable()->after('temporary_password_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['temporary_password_until', 'temporary_password_via']));
    }
};
