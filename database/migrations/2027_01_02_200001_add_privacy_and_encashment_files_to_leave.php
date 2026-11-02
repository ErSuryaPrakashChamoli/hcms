<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity RQ-4 (matrix 20 §20.1.18 Leave #8, Encashment #5):
 * - `leave_requests.is_private`: colleagues see a private leave as "Away", without its type or reason. False on every
 *   existing request and by default, which keeps every screen as it was.
 * - `leave_encashments.attachments`: the supporting files of an encashment request (paths on the documents disk, under
 *   tenants/{tenant}/leave-encashments/), served through the signed file route. Null on existing requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('is_private')->default(false);
        });
        Schema::table('leave_encashments', function (Blueprint $table) {
            $table->json('attachments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leave_encashments', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};
