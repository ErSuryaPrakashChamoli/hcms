<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk upload of employee documents (Admin → Data import → Employee documents): HR uploads many zips at once, each named
 * "employee code_name" (or one zip holding a folder or zip per employee); every file inside is matched to a document type
 * by its name ("Aadhar card.pdf" → Aadhaar) and stored as an ordinary employee document.
 *
 * - document_upload_batches: one row per upload — what to do with documents already on file, whether the usual "document
 *   uploaded" notifications go out, the uploaded zips (kept on the documents disk only until the upload has run or is
 *   discarded), the counts, who and when.
 * - document_upload_items: one row per file found in the zips — where it is (which zip, which inner zip, the path inside and its position, which is what is read),
 *   the employee folder it came in and the employee it resolved to, the document type matched (and how) or chosen, the
 *   dates read from the name, and what happened: ready, waiting for a type, skipped, blocked (with the reason), uploaded
 *   (the employee document it created) or failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_upload_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('review');
            $table->json('options')->nullable();
            $table->string('source_disk', 32)->nullable();
            $table->json('sources')->nullable();
            $table->unsignedInteger('zip_count')->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('ready')->default(0);
            $table->unsignedInteger('needs_type')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('blocked')->default(0);
            $table->unsignedInteger('uploaded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'document_upload_batches_creator_fk')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'document_upload_batches_tenant_status_idx');
            $table->index(['tenant_id', 'created_by'], 'document_upload_batches_tenant_creator_idx');
        });

        Schema::create('document_upload_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_upload_batch_id')->constrained('document_upload_batches', 'id', 'document_upload_items_batch_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('source')->default(0);
            $table->string('container', 500)->nullable();
            $table->unsignedInteger('container_index')->nullable();
            $table->string('entry', 1000);
            $table->unsignedInteger('entry_index')->default(0);
            $table->string('folder');
            $table->string('file_name');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('employee_code', 64)->nullable();
            $table->string('name_given')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees', 'id', 'document_upload_items_employee_fk')->nullOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('document_types', 'id', 'document_upload_items_type_fk')->nullOnDelete();
            $table->string('matched_by', 16)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('status', 16)->default('ready');
            $table->string('flag', 16)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('employee_document_id')->nullable()->constrained('employee_documents', 'id', 'document_upload_items_document_fk')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['document_upload_batch_id', 'status'], 'document_upload_items_batch_status_idx');
            $table->index(['tenant_id', 'employee_id'], 'document_upload_items_tenant_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_upload_items');
        Schema::dropIfExists('document_upload_batches');
    }
};
