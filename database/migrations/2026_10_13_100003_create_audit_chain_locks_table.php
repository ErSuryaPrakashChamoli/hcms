<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 audit hardening: one lock row per audit hash chain (tenant or platform).
 *
 * Writers of a chain serialise on its row before reading the previous hash. Locking the last audit
 * row instead let concurrent writers deadlock on InnoDB gap locks under load: many simultaneous survey
 * submissions in one tenant showed it.
 *
 * Additive only; the audit events and their hashes are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_locks', function (Blueprint $table) {
            $table->string('chain', 32)->primary();
        });

        DB::table('audit_chain_locks')->insert(['chain' => 'platform']);
        DB::table('tenants')->orderBy('id')->pluck('id')->chunk(500)->each(
            fn ($ids) => DB::table('audit_chain_locks')->insert($ids->map(fn ($id) => ['chain' => 'tenant:'.$id])->all())
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_locks');
    }
};
