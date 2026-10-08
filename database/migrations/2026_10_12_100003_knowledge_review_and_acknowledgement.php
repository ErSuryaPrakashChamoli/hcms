<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 knowledge base. Articles move Draft → Review → Approved → Published → Archived:
 * - the reviewer and approver are recorded, and neither is the author;
 * - readers are served the immutable published version (`published_version`), never the working copy.
 *
 * Versions carry a content hash. Acknowledgements are per version and record the version, the hash,
 * the source and the IP address. They are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->unsignedInteger('published_version')->nullable()->after('version');
            $table->foreignId('reviewer_id')->nullable()->after('author_id')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_for_review_at')->nullable()->after('reviewer_id');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_for_review_at');
            $table->text('review_note')->nullable()->after('reviewed_at');
            $table->foreignId('approved_by')->nullable()->after('review_note')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
        DB::table('articles')->whereIn('status', ['published', 'archived'])->update(['published_version' => DB::raw('version')]);

        Schema::table('article_versions', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('title');
            $table->string('category', 32)->nullable()->after('summary');
            $table->string('body_hash', 64)->nullable()->after('body');
            $table->foreignId('reviewed_by')->nullable()->after('published_by')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('reviewed_by')->constrained('users')->nullOnDelete();
        });
        foreach (DB::table('article_versions')->whereNull('body_hash')->orderBy('id')->get(['id', 'title', 'body']) as $row) {
            DB::table('article_versions')->where('id', $row->id)->update(['body_hash' => hash('sha256', $row->title."\n".$row->body)]);
        }

        Schema::table('article_reads', function (Blueprint $table) {
            $table->foreignId('article_version_id')->nullable()->after('version')->constrained()->cascadeOnDelete();
            $table->string('version_hash', 64)->nullable()->after('article_version_id');
            $table->string('source', 16)->nullable()->after('acknowledged_at');
            $table->string('ip_address', 45)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('article_reads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('article_version_id');
            $table->dropColumn(['version_hash', 'source', 'ip_address']);
        });
        Schema::table('article_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['summary', 'category', 'body_hash']);
        });
        DB::table('articles')->whereIn('status', ['in_review', 'approved'])->update(['status' => 'draft']);
        Schema::table('articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewer_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['published_version', 'submitted_for_review_at', 'reviewed_at', 'review_note', 'approved_at']);
        });
    }
};
