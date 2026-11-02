<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity WO-14 (matrix 23 §23.7.3–23.7.4, §23.9 #20 "Share letters / documents"): HR shares ready-made files with many
 * employees at once.
 *
 * - document_share_batches: one row per share (the register): document type, title, how the files were given (a zip of files
 *   with a sheet of employee code + file name, or one common file for everyone chosen), the optional message, how employees
 *   are told (in-app; e-mail off by default), counts shared / failed, who and when; the uploaded zip / sheet / common file
 *   are kept on the documents disk only until the share has run. A batch is unpublished (hidden from employees), never
 *   deleted (D-WO5).
 * - document_share_items: one row per sheet row or chosen employee — the employee, the employee document created (an
 *   ordinary employee document the employee sees in My HR / My profile), or the reason it failed; unpublished with who,
 *   when and why (the document is archived, its file kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_share_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('document_types', 'id', 'document_share_batches_type_fk')->nullOnDelete();
            $table->string('title');
            $table->string('mode', 16);
            $table->text('message')->nullable();
            $table->boolean('notify_in_app')->default(true);
            $table->boolean('email')->default(false);
            $table->string('email_to', 16)->nullable();
            $table->json('selection')->nullable();
            $table->string('source_disk', 32)->nullable();
            $table->string('source_path')->nullable();
            $table->string('source_name')->nullable();
            $table->string('sheet_path')->nullable();
            $table->string('sheet_name')->nullable();
            $table->json('unused_files')->nullable();
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('shared')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('emailed')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'document_share_batches_creator_fk')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->foreignId('unpublished_by')->nullable()->constrained('users', 'id', 'document_share_batches_unpublisher_fk')->nullOnDelete();
            $table->string('unpublish_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'document_share_batches_tenant_status_idx');
            $table->index(['tenant_id', 'document_type_id'], 'document_share_batches_tenant_type_idx');
            $table->index(['tenant_id', 'created_by'], 'document_share_batches_tenant_creator_idx');
        });

        Schema::create('document_share_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_share_batch_id')->constrained('document_share_batches', 'id', 'document_share_items_batch_fk')->cascadeOnDelete();
            $table->unsignedInteger('row')->nullable();
            $table->string('employee_code', 64)->nullable();
            $table->string('file_name')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees', 'id', 'document_share_items_employee_fk')->nullOnDelete();
            $table->foreignId('employee_document_id')->nullable()->constrained('employee_documents', 'id', 'document_share_items_document_fk')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('reason', 500)->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->foreignId('notification_delivery_id')->nullable()->constrained('notification_deliveries', 'id', 'document_share_items_delivery_fk')->nullOnDelete();
            $table->timestamp('unpublished_at')->nullable();
            $table->foreignId('unpublished_by')->nullable()->constrained('users', 'id', 'document_share_items_unpublisher_fk')->nullOnDelete();
            $table->string('unpublish_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['document_share_batch_id', 'status'], 'document_share_items_batch_status_idx');
            $table->index(['tenant_id', 'employee_id'], 'document_share_items_tenant_employee_idx');
            $table->index(['tenant_id', 'status', 'shared_at'], 'document_share_items_tenant_status_idx');
            $table->unique('employee_document_id', 'document_share_items_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_share_items');
        Schema::dropIfExists('document_share_batches');
    }
};
