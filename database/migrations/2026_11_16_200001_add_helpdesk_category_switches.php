<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HD-A — Helpdesk: what a ticket category is for (purpose), whether resolving needs a root cause
 * (RCA) and whether the requester rates the help; the root cause recorded on the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->string('purpose', 32)->default('general')->after('description')->comment('peopleos.servicedesk.category_purposes');
            $table->boolean('rca_required')->default(false)->after('purpose');
            $table->boolean('feedback_enabled')->default(true)->after('rca_required');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->text('root_cause')->nullable()->after('resolution');
            $table->string('root_cause_code', 48)->nullable()->after('root_cause')->comment('peopleos.servicedesk.root_causes');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['root_cause', 'root_cause_code']);
        });
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'rca_required', 'feedback_enabled']);
        });
    }
};
