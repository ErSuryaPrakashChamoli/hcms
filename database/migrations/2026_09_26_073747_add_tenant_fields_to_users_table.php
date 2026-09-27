<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null tenant_id denotes a platform-level user (Markedge staff), never a tenant user.
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_platform_admin')->default(false)->after('password');
            $table->string('status', 32)->default('active')->after('is_platform_admin');
            $table->string('timezone', 64)->nullable()->after('status');
            $table->timestamp('last_login_at')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['is_platform_admin', 'status', 'timezone', 'last_login_at']);
        });
    }
};
