<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity WO-15: what kind of sign-in an account is. `standard` (every account so far: administrators, employees,
 * alumni) or `partner` (a business partner's sign-in, created only by BusinessPartners::invite). The kind gives no access
 * by itself: a partner holds only the roles an administrator assigns, and is never linked to an employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('kind', 16)->default('standard')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
