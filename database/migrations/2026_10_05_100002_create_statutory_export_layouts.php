<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 6.2 — export layouts as versioned, immutable, independently verified artefacts (§16–17).
 | Platform-level. Returns record the layout version they were exported with and the result of the
 | structural validation of the file; portal validation is recorded separately (§24).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_export_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('authority', 32)->nullable();
            $table->json('specification');
            $table->string('checksum', 64);
            $table->string('status', 16)->default('draft');
            $table->string('source_url', 500)->nullable();
            $table->string('source_title')->nullable();
            $table->date('retrieved_at')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('submission_notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('statutory_export_layouts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version']);
        });

        Schema::table('statutory_returns', function (Blueprint $table) {
            $table->foreignId('export_layout_id')->nullable()->after('format_verification_status')->constrained('statutory_export_layouts')->restrictOnDelete();
            $table->json('local_validation')->nullable()->after('export_checksum');
            $table->timestamp('locally_validated_at')->nullable()->after('local_validation');
            $table->string('portal_validation_result', 16)->nullable()->after('locally_validated_at');
            $table->string('portal_validation_reference', 128)->nullable()->after('portal_validation_result');
            $table->timestamp('portal_validated_at')->nullable()->after('portal_validation_reference');
            $table->foreignId('portal_validated_by')->nullable()->after('portal_validated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('statutory_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('portal_validated_by');
            $table->dropConstrainedForeignId('export_layout_id');
            $table->dropColumn(['local_validation', 'locally_validated_at', 'portal_validation_result', 'portal_validation_reference', 'portal_validated_at']);
        });
        Schema::dropIfExists('statutory_export_layouts');
    }
};
