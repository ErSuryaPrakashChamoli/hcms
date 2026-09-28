<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 6.3 — "configured" vs "verified" for legal entities and establishments (§18–19): a
 | maker-checker verification against the registration certificate / incorporation document.
 | Changing an identity field of a verified record resets it to unverified.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['legal_entities', 'establishments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('verification_status', 16)->default('unverified')->after('status');
                $table->string('verification_reference')->nullable()->after('verification_status');
                $table->string('verification_evidence_path')->nullable()->after('verification_reference');
                $table->string('verification_evidence_sha256', 64)->nullable()->after('verification_evidence_path');
                $table->foreignId('verification_submitted_by')->nullable()->after('verification_evidence_sha256')->constrained('users')->nullOnDelete();
                $table->timestamp('verification_submitted_at')->nullable()->after('verification_submitted_by');
                $table->foreignId('verified_by')->nullable()->after('verification_submitted_at')->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable()->after('verified_by');
                $table->text('verification_notes')->nullable()->after('verified_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['legal_entities', 'establishments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('verified_by');
                $table->dropConstrainedForeignId('verification_submitted_by');
                $table->dropColumn(['verification_status', 'verification_reference', 'verification_evidence_path', 'verification_evidence_sha256', 'verification_submitted_at', 'verified_at', 'verification_notes']);
            });
        }
    }
};
