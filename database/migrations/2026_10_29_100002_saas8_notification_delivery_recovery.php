<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.8 notification delivery reliability. Additive.
 *
 * - resend_count: how many times a failed delivery was sent again by an administrator (each resend is audited).
 * - (tenant_id, status, updated_at): the recovery sweep finds deliveries stuck in `sending` or left `queued` per tenant
 *   without scanning the tenant's whole delivery log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->unsignedSmallInteger('resend_count')->default(0)->after('error');
            $table->index(['tenant_id', 'status', 'updated_at'], 'notification_deliveries_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex('notification_deliveries_recovery_index');
            $table->dropColumn('resend_count');
        });
    }
};
