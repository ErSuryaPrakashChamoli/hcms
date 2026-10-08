<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.8: every audit write reads its chain's head (`WHERE tenant_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE`).
 * Without a (tenant_id, id) index MySQL answered it with a backward scan of the primary key (EXPLAIN: key PRIMARY,
 * "Backward index scan"), reading, and under FOR UPDATE locking, other tenants' newest audit rows until it reached one
 * of this tenant's: the cost and the lock footprint grew with every other tenant's activity. With this index the
 * head is one index entry of the chain itself; AuditRecorder names it (the optimizer kept the primary-key scan otherwise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->index(['tenant_id', 'id'], 'audit_events_chain_head_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex('audit_events_chain_head_index');
        });
    }
};
