<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance reports: location-based applicability and traceable packs.
 *
 * establishments.appropriate_government records whether the Central or the State Government is the appropriate
 * government for the establishment under the Labour Codes (it decides which rules and register forms apply);
 * establishments.industry the broad activity (factories, mines, plantations, construction and IT/ITES have their own
 * rules). Both are nullable: a missing value is reported as configuration required, never assumed.
 *
 * compliance_report_packs gain what a pack was built from (scope fingerprint of the requester's access, catalogue
 * checksum, rule versions and payroll runs read, data gaps) and an authorised review (by someone other than the
 * requester).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->string('appropriate_government', 16)->nullable()->after('establishment_type')->comment('central|state');
            $table->string('industry', 32)->nullable()->after('appropriate_government');
        });

        Schema::table('compliance_report_packs', function (Blueprint $table) {
            $table->string('scope_fingerprint', 64)->nullable()->after('requested_by');
            $table->json('metadata')->nullable()->after('manifest')->comment('catalogue checksum, rule versions, payroll runs, data gaps');
            $table->foreignId('reviewed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_note')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_report_packs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['scope_fingerprint', 'metadata', 'reviewed_at', 'review_note']);
        });

        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn(['appropriate_government', 'industry']);
        });
    }
};
