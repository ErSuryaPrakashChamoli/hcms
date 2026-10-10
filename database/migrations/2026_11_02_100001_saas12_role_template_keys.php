<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.12: the permission keys of its template that a system role has already been given. `peopleos:sync-permissions`
 * (run on every deploy) gives a system role only the template keys not yet recorded here, so a permission the tenant
 * removed stays removed. Additive and nullable: a role without a value takes its current template as the baseline at the
 * next sync, granting nothing (exact for this release, which introduces no permission key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('template_keys')->nullable()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('template_keys');
        });
    }
};
