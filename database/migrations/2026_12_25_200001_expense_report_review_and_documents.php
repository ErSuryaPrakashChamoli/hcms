<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity XO-3 (matrix 26 §26.4.2 #11, §26.4.3 #10):
 *
 * - expense_receipts: the approver's "Suspected" decision (owner decision D-XO7 / EXP-13, behind the Setup centre setting
 *   expense.review.suspect_button, off by default): when it was held out, by whom and why; when the suspicion was cleared
 *   and by whom (the clearing decision's note is the line's approver note). Null everywhere = never suspected (today).
 * - expense_document_downloads: queued zips of the receipt files and / or cover letters of chosen expense reports (bulk
 *   "Download documents"), kept for a few hours and fetched only by the person who asked (like letter_downloads).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_receipts', function (Blueprint $table) {
            $table->timestamp('suspected_at')->nullable()->after('approver_note');
            $table->foreignId('suspected_by')->nullable()->after('suspected_at')->constrained('users', 'id', 'expense_receipts_suspected_by_fk')->nullOnDelete();
            $table->string('suspicion_note', 500)->nullable()->after('suspected_by');
            $table->timestamp('suspicion_cleared_at')->nullable()->after('suspicion_note');
            $table->foreignId('suspicion_cleared_by')->nullable()->after('suspicion_cleared_at')->constrained('users', 'id', 'expense_receipts_suspicion_cleared_by_fk')->nullOnDelete();
        });

        Schema::create('expense_document_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->json('report_ids');
            $table->json('documents');
            $table->string('selection', 24)->default('selected');
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('included')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('files')->default(0);
            $table->string('file_disk', 32)->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'requested_by'], 'expense_doc_downloads_tenant_requester_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_document_downloads');
        Schema::table('expense_receipts', function (Blueprint $table) {
            $table->dropForeign('expense_receipts_suspicion_cleared_by_fk');
            $table->dropForeign('expense_receipts_suspected_by_fk');
            $table->dropColumn(['suspected_at', 'suspected_by', 'suspicion_note', 'suspicion_cleared_at', 'suspicion_cleared_by']);
        });
    }
};
