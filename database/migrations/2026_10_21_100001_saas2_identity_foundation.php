<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.2 foundation hardening (identity and tenant governance; no commercial tables).
 *
 * - tenants.session_epoch / users.session_epoch: raising one ends the sessions opened under the old value
 *   (tenant suspension, MFA reset, password reset, sign out everywhere). Additive, default 0.
 * - user_invitations: one-time, expiring, revocable invitation tokens (SHA-256 only), tenant-owned.
 * - workflows.webhook_signing_secret: the HMAC secret workflow webhook nodes sign with (encrypted).
 * - users.email_verified_at backfill: accounts that existed before e-mail verification was enforced were
 *   created by administrators or the seeder and are treated as verified (recorded as their creation time);
 *   they re-verify on their next e-mail change. Not reversed by down(): a later null would lock them out.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'session_epoch')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->unsignedInteger('session_epoch')->default(0)->after('status');
            });
        }
        if (! Schema::hasColumn('users', 'session_epoch')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('session_epoch')->default(0)->after('remember_token');
            });
        }

        if (! Schema::hasTable('user_invitations')) {
            Schema::create('user_invitations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->char('token_hash', 64)->unique();
                $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_reason', 255)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'user_id'], 'user_invitations_tenant_user_index');
            });
        }

        if (! Schema::hasColumn('workflows', 'webhook_signing_secret')) {
            Schema::table('workflows', function (Blueprint $table) {
                $table->text('webhook_signing_secret')->nullable();
            });
        }

        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('workflows', 'webhook_signing_secret')) {
            Schema::table('workflows', fn (Blueprint $table) => $table->dropColumn('webhook_signing_secret'));
        }
        Schema::dropIfExists('user_invitations');
        if (Schema::hasColumn('users', 'session_epoch')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('session_epoch'));
        }
        if (Schema::hasColumn('tenants', 'session_epoch')) {
            Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('session_epoch'));
        }
    }
};
