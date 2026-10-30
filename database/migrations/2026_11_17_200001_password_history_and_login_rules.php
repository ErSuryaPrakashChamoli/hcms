<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity (Governance → Security): the hashes of a tenant user's recent passwords, kept only while the
 * tenant restricts reuse (security.password_history_count), and the mark on a user whose sign-in was turned off by a
 * lifecycle-status rule (security.disable_login_states), so leaving the status turns it back on only for those users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('password');
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id'], 'password_histories_user_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('disabled_by_rule')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_by_rule');
        });
        Schema::dropIfExists('password_histories');
    }
};
