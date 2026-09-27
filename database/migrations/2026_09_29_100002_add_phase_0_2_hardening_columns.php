<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.2: additive hardening columns.
 * - audit_events.operation_id groups the events of one bulk operation.
 * - compliance_rules.verification_status marks statutory rules as illustrative until verified
 *   against official sources; verified_at records when that happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('audit_events', 'operation_id')) {
            Schema::table('audit_events', function (Blueprint $table) {
                $table->string('operation_id', 26)->nullable()->after('request_id')->index();
            });
        }

        if (! Schema::hasColumn('compliance_rules', 'verification_status')) {
            Schema::table('compliance_rules', function (Blueprint $table) {
                $table->string('verification_status', 16)->default('illustrative')->after('status');
            });
        }

        if (! Schema::hasColumn('compliance_rules', 'verified_at')) {
            Schema::table('compliance_rules', function (Blueprint $table) {
                $table->timestamp('verified_at')->nullable()->after('verification_status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('audit_events', fn (Blueprint $table) => $table->dropColumn('operation_id'));
        Schema::table('compliance_rules', fn (Blueprint $table) => $table->dropColumn(['verification_status', 'verified_at']));
    }
};
